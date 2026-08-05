<?php

namespace App\Scrapers\BancoCiudad;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\DTOs\PromotionLocationDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use RuntimeException;
use Throwable;

/**
 * bancociudad.com.ar/beneficios/promo is an Angular SPA (`<app-root>`,
 * webpack chunks `main-*.js`/`polyfills-*.js`) — a plain GET of that path
 * returns HTTP 404 with the SPA's own empty shell (a known server
 * misconfiguration confirmed live: every path under `/beneficios/`, including
 * the real listing route, answers 404 while still shipping the full HTML/JS —
 * harmless here since this scraper never renders it, it only reverse-engineers
 * the bundle to find the real API). No public source map is served for
 * `main-*.js`, so the API was found by grepping the downloaded bundle itself:
 * the app boots by fetching `GET /beneficios_rest/cfg` (confirmed live,
 * `{"retorno":{"URL_BENEFICIOS_REST":"https://www.bancociudad.com.ar/beneficios_rest/",...}}`)
 * and uses that same-origin, genuinely public, unauthenticated REST API for
 * everything else (confirmed live: no cookies/API key/Authorization header
 * required on any call below). F5 Distributed Cloud fronts this host
 * (`server: volt-adc` response header) but never challenged a plain request
 * with this project's shared realistic-Chrome User-Agent — no WAF bypass
 * needed, unlike Santander's Akamai tarpit.
 *
 * Stage 1 is `POST beneficios_rest/busqueda`, body
 * `{"data":{...filters...},"header":{}}` with every filter set to its "no
 * filter" sentinel (`rubros:[0]`, `medios_de_pago:[0]`, `comercio:""`,
 * `dias:""`, `destacado:false` — confirmed live via the bundle's own
 * `formGroup` defaults) — confirmed live to return the complete, unfiltered
 * catalog as `retorno.beneficios[]`, plus `retorno.cantidadTotalBeneficios`
 * and a `retorno.rubrosPorCliente[]` category taxonomy (`{tipo_cliente,
 * rubros: [{id, nombre, ...}]}`) in the very same response.
 *
 * IMPORTANT — what looked like a backend pagination bug in an earlier version
 * of this scraper turned out to be a mismatch with the server's own,
 * hardcoded internal page size: the site's own Angular bundle
 * (`main-*.js`, confirmed live) always requests `tamano_pagina: 12` — its
 * `formGroup` default (`pageSize:[12]`) — and the backend computes each
 * page's offset as `12 * (numero_pagina - 1)` regardless of what
 * `tamano_pagina` the caller actually sends. A request for `numero_pagina: 2`
 * with, say, `tamano_pagina: 100` still only advances the offset by 12, so it
 * mostly re-returns page 1's own items — which is exactly the "~12 genuinely
 * new ids per page no matter the requested size" behaviour a naive page-walk
 * showed. Confirmed live: walking pages while sending the SAME `tamano_pagina:
 * 12` the real site uses advances correctly with no overlap (page 1 and page
 * 2 return entirely disjoint ids). A separate, real problem this scraper
 * still has to handle: asking the backend for an oversized single page (e.g.
 * `tamano_pagina: 5000`, an earlier version's approach to avoid pagination
 * entirely) intermittently gets an HTTP 500
 * (`{"mensaje":"ERROR","retorno":"Lo sentimos, pero el servicio no se
 * encuentra disponible en este momento."}`) — confirmed live, repeatedly,
 * including moments where an identical request via plain `curl` succeeded
 * immediately after a `php artisan`-issued one failed — i.e. the backend
 * itself seems to buckle under that one huge query, independent of caller
 * identity. Walking the catalog in the same small, realistic pages the real
 * site uses (`PAGE_SIZE = 12`) avoids ever sending that one expensive query.
 *
 * Each listing entry already carries the discount mechanics (`descuento`,
 * `cuotas`), a narrative sentence (`resumen`, e.g. "20% de descuento y 3
 * cuotas sin interés para compras realizadas los días viernes con tarjetas
 * de crédito Visa o Mastercard..."), accepted cards (`medios_pago[]`), a
 * weekday bitmask (`dias`, a 7-char string positioned Lu-Ma-Mi-Ju-Vi-Sa-Do,
 * confirmed live against `todos_dias`/specific-day promos), and the
 * merchant's id/name/logo — but only a numeric `rubroId` for category (needs
 * the taxonomy above), no validity dates, no full legal text, and no branch
 * addresses.
 *
 * Stage 2 (double scraping, genuinely needed — the listing is missing real
 * data, not just cosmetic fields) is `POST beneficios_rest/{id}`, body
 * `{"data":{"latitud":null,"longitud":null},"header":{}}` (confirmed live:
 * the app's own detail route is `/beneficios/detalle/:id` and builds this
 * exact call from the route's `:id` param). One call per promo returns
 * everything the listing lacks at once — `retorno.beneficio.fechaDesde`/
 * `fechaHasta` (real ISO validity dates), `retorno.beneficio.legales` (the
 * full promo-specific Términos y Condiciones, sometimes plain text, sometimes
 * HTML), `retorno.beneficio.nombreRubro` (the category name directly, no
 * taxonomy lookup needed), AND `retorno.sucursales_cercanas[]` (physical
 * branches) all in the same response — no third stage needed for locations.
 * (`retorno.comercio.web`, the merchant's own site, is real too but has no
 * home in `PromotionDTO` — no other wallet in this project maps one either —
 * so it's left in `rawPayload` only.) Passing `latitud`/`longitud: null`
 * (no real device geolocation available server-side) still returns real
 * branches confirmed live (52 for a nationwide bookstore chain,
 * 1 for a single-location shop, 0 for online-only promos) rather than an
 * error or an empty/wrong list, so this scraper never bothers simulating a
 * position. An invalid/expired id returns HTTP 200 with
 * `{"mensaje":"ERROR",...}` rather than a 404 (confirmed live) — like every
 * other wallet's own stage 2 in this project, this is best-effort: a failure
 * (network error or a non-"OK" `mensaje`) simply leaves the promo with the
 * listing's own fields and no dates/terms/locations, it never drops the promo
 * or aborts the scrape.
 *
 * `descuento` is a reintegro (cashback credited to the account later), never
 * an at-checkout discount, despite the UI's own card badge literally reading
 * "{descuento}% de descuento" (confirmed live via the bundle's own
 * `OP`/render-function string) — every sampled promo's own `legales` text
 * says so explicitly regardless of category ("El descuento se acreditará en
 * la cuenta del cliente...", "El reintegro se acreditará..."), sampled across
 * Comercios Vecinos/Buepp, Librerías, Combustible, and Supermercados promos,
 * with zero counter-evidence of an immediate discount anywhere in the
 * catalog. So it's mapped to `cashbackPercentage`, never
 * `discountPercentage` — the same reintegro-vs-descuento distinction already
 * used for Galicia/Credicoop/Santander/ICBC elsewhere in this project. The
 * `title` text still uses the site's own literal wording ("X% de descuento",
 * "N cuotas sin interés") since that's what a user actually sees on the
 * card — only the DTO's own semantic field captures the true mechanic.
 *
 * Neither the listing nor the detail call has one ready-made headline field
 * (unlike Galicia's `promocion` or Credicoop's `display_name`) — the site's
 * own card instead renders `descuento`/`cuotas`/`promoDosPorUno` as separate
 * badges (confirmed live via the bundle's own `app-card-beneficio` template).
 * `title` reconstructs that same badge text directly from those real numeric
 * fields ("30% de descuento", "24 cuotas sin interés", joined with " y " when
 * both apply, "2x1" appended when `promoDosPorUno == "S"` — never observed
 * live in the ~1195-item catalog sampled, but a real field so still handled),
 * falling back to the narrative `resumen` and finally the merchant name on
 * the (unobserved live) case where a promo has neither.
 *
 * `sin_tope` (whether there's no cap at all) is the only structured signal
 * for a reimbursement cap — the actual cap amount only ever appears buried in
 * free-text `legales` in inconsistent phrasing ("tope de $15.000 (quince mil)
 * pesos mensuales", "tope de reintegro de $25.000 mensual", "tope máximo de
 * devolución mensual... de $10.000") — far too unreliable to regex without
 * fabricating wrong numbers, so `reimbursementCap` stays null, the same
 * accepted limitation already documented for Credicoop. `minimumPurchase`
 * has no structured field at all either (only occasional free-text mentions,
 * e.g. "Mínimo de compra: $75.000") — also left null.
 *
 * `sucursales_cercanas[]` entries carry a single pre-formatted `direccion`
 * string, not separate street/city/province fields, AND that formatting is
 * itself inconsistent between promos — confirmed live: some are
 * "{street}, {city}, {province}" (e.g. "Av. de Mayo 637, CABA, Buenos
 * Aires"), others are a reverse-geocoded "{street}, {postal code + city},
 * {country}" (e.g. "Av. Escalada 4402, C1439 Cdad. Autónoma de Buenos Aires,
 * Argentina") — splitting reliably on commas would misfile the country as a
 * province for the second shape, so the whole string is kept as-is in
 * `address`, with `city`/`province` left null rather than guessed.
 *
 * Categories (`nombreRubro` on the detail call, or the taxonomy resolved from
 * the listing's own `rubrosPorCliente` when detail fails): confirmed live, 25
 * distinct rubros in active use, Spanish Title Case with accents preserved
 * ("Hogar y decoración", "Bicicleterías", "Comercios Vecinos"). Not
 * cross-checked against `config/category_aliases.php` here per the task's own
 * scope.
 */
class BancoCiudadScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string API_BASE = 'https://www.bancociudad.com.ar/beneficios_rest/';

    private const string DETAIL_URL_BASE = 'https://www.bancociudad.com.ar/beneficios/detalle/';

    /**
     * See the class docblock: the real site's own page size, and the one
     * the backend's pagination offset math is hardcoded to — any other
     * value silently breaks pagination instead of just being "unrealistic".
     */
    private const int PAGE_SIZE = 12;

    /**
     * Safety cap on listing pages so a pagination bug (or a runaway
     * catalog) can't turn this into an unbounded loop. The catalog observed
     * live is ~1200 promotions / 12 per page = 100 pages.
     */
    private const int MAX_LISTING_PAGES = 500;

    /**
     * `dias` is a 7-char positional string (confirmed live, e.g. "LMMJVSD"
     * for every day, "----V--" for Fridays only) — index 0 is Monday.
     *
     * @var array<int, string>
     */
    private const array DAY_POSITIONS = [
        0 => 'Lunes', 1 => 'Martes', 2 => 'Miércoles', 3 => 'Jueves',
        4 => 'Viernes', 5 => 'Sábado', 6 => 'Domingo',
    ];

    public function walletSlug(): string
    {
        return 'banco_ciudad';
    }

    public function scrape(): iterable
    {
        [$items, $categoryMap] = $this->fetchListing();

        foreach ($items as $item) {
            $id = $item['id'] ?? null;

            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $id = (string) $id;
            $detail = $this->fetchDetail($id);
            $dto = $this->buildDto($id, $item, $detail, $categoryMap);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * Walks the catalog using the real site's own page size (see the class
     * docblock — any other size breaks the backend's pagination offset
     * math). The category taxonomy is identical on every page's response,
     * so it's only kept from the first one.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function fetchListing(): array
    {
        $items = [];
        $categoryMap = [];
        $page = 1;

        do {
            $result = $this->search($page);

            if ($page === 1) {
                $categoryMap = $result['categoryMap'];
            }

            foreach ($result['items'] as $item) {
                $items[] = $item;
            }

            $page++;
        } while (count($result['items']) > 0 && $page <= self::MAX_LISTING_PAGES);

        return [$items, $categoryMap];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, categoryMap: array<int, string>}
     */
    private function search(int $page): array
    {
        $response = $this->http()
            ->post(self::API_BASE.'busqueda', [
                'data' => [
                    'categoria_shoppings' => false,
                    'comercio' => '',
                    'dias' => '',
                    'distancia' => 0,
                    'latitud' => null,
                    'longitud' => null,
                    'limite_descuento' => 0,
                    'medios_de_pago' => [0],
                    'rubros' => [0],
                    'tipo_cliente' => 'persona',
                    'destacado' => false,
                    'ordenamiento' => 'masReciente',
                    'numero_pagina' => $page,
                    'tamano_pagina' => self::PAGE_SIZE,
                ],
                'header' => [],
            ])
            ->throw()
            ->json();

        if (! is_array($response) || ($response['mensaje'] ?? null) !== 'OK') {
            throw new RuntimeException('Banco Ciudad: unexpected busqueda response: '.json_encode($response));
        }

        $retorno = is_array($response['retorno'] ?? null) ? $response['retorno'] : [];
        $items = is_array($retorno['beneficios'] ?? null) ? array_values(array_filter($retorno['beneficios'], 'is_array')) : [];
        $total = is_numeric($retorno['cantidadTotalBeneficios'] ?? null) ? (int) $retorno['cantidadTotalBeneficios'] : count($items);

        return ['items' => $items, 'total' => $total, 'categoryMap' => $this->buildCategoryMap($retorno)];
    }

    /**
     * Builds a `rubroId => nombre` map from the listing response's own
     * `rubrosPorCliente` taxonomy — only the "PERSONA" group, since every
     * listing item observed live is `tipo_cliente: "PERSONA"`. Used as a
     * fallback category source when stage 2 (which has `nombreRubro`
     * directly) fails for a given promo.
     *
     * @param  array<string, mixed>  $retorno
     * @return array<int, string>
     */
    private function buildCategoryMap(array $retorno): array
    {
        $groups = is_array($retorno['rubrosPorCliente'] ?? null) ? $retorno['rubrosPorCliente'] : [];
        $map = [];

        foreach ($groups as $group) {
            if (! is_array($group) || mb_strtoupper((string) ($group['tipo_cliente'] ?? '')) !== 'PERSONA') {
                continue;
            }

            $rubros = is_array($group['rubros'] ?? null) ? $group['rubros'] : [];

            foreach ($rubros as $rubro) {
                if (is_array($rubro) && is_numeric($rubro['id'] ?? null)) {
                    $name = $this->stringOrNull($rubro['nombre'] ?? null);

                    if ($name !== null) {
                        $map[(int) $rubro['id']] = $name;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Stage 2 enrichment for a single promo. Never throws — any failure
     * (network error, unexpected shape, or the confirmed-live
     * `{"mensaje":"ERROR"}` shape for an invalid id) simply returns `null`,
     * and the caller falls back to the listing's own fields.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDetail(string $id): ?array
    {
        try {
            $response = $this->http()
                ->post(self::API_BASE.$id, [
                    'data' => ['latitud' => null, 'longitud' => null],
                    'header' => [],
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($response) || ($response['mensaje'] ?? null) !== 'OK') {
            return null;
        }

        return is_array($response['retorno'] ?? null) ? $response['retorno'] : null;
    }

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     * @param  array<int, string>  $categoryMap
     */
    private function buildDto(string $id, array $listing, ?array $detail, array $categoryMap): ?PromotionDTO
    {
        $beneficio = is_array($detail['beneficio'] ?? null) ? $detail['beneficio'] : null;
        $comercio = is_array($detail['comercio'] ?? null) ? $detail['comercio'] : null;

        $merchantName = $this->stringOrNull($comercio['nombre'] ?? null)
            ?? $this->stringOrNull($listing['comercio_nombre'] ?? null);

        if ($merchantName === null) {
            return null;
        }

        $cashback = $this->positiveFloatOrNull($this->fromDetailOrListing($beneficio, $listing, 'descuento'));
        $installments = $this->positiveIntOrNull($this->fromDetailOrListing($beneficio, $listing, 'cuotas'));
        $isDosPorUno = $this->fromDetailOrListing($beneficio, $listing, 'promoDosPorUno') === 'S';
        $resumen = $this->stringOrNull($this->fromDetailOrListing($beneficio, $listing, 'resumen'));

        $rubroId = is_numeric($listing['rubroId'] ?? null) ? (int) $listing['rubroId'] : null;
        $category = $this->stringOrNull($beneficio['nombreRubro'] ?? null)
            ?? ($rubroId !== null ? ($categoryMap[$rubroId] ?? null) : null);

        $logoPath = $this->stringOrNull($comercio['logo'] ?? null)
            ?? $this->stringOrNull($listing['comercio_logo'] ?? null);

        $legales = $this->stringOrNull($beneficio['legales'] ?? null);

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $this->buildTitle($cashback, $installments, $isDosPorUno, $resumen, $merchantName),
            merchantIconUrl: $logoPath !== null ? self::API_BASE.$logoPath : null,
            category: $category,
            description: $resumen,
            cashbackPercentage: $cashback,
            installments: $installments,
            validDays: $this->resolveValidDays($this->fromDetailOrListing($beneficio, $listing, 'dias')),
            startDate: $this->parseIsoDate($this->stringOrNull($beneficio['fechaDesde'] ?? null)),
            endDate: $this->parseIsoDate($this->stringOrNull($beneficio['fechaHasta'] ?? null)),
            terms: $legales !== null ? $this->htmlToPlainText($legales) : null,
            url: self::DETAIL_URL_BASE.$id,
            externalId: $id,
            paymentMethods: $this->resolvePaymentMethods($this->fromDetailOrListing($beneficio, $listing, 'medios_pago')),
            locations: $this->resolveLocations($detail['sucursales_cercanas'] ?? null),
            rawPayload: $detail ?? $listing,
        );
    }

    /**
     * Prefers stage 2's `beneficio` for a field when present, falling back
     * to the listing's own copy of the same key (both calls share field
     * names for everything but `nombreRubro`/`legales`/dates, which only
     * exist on `beneficio`).
     *
     * @param  array<string, mixed>|null  $beneficio
     * @param  array<string, mixed>  $listing
     */
    private function fromDetailOrListing(?array $beneficio, array $listing, string $key): mixed
    {
        if ($beneficio !== null && array_key_exists($key, $beneficio)) {
            return $beneficio[$key];
        }

        return $listing[$key] ?? null;
    }

    /**
     * See the class docblock: neither call has one ready-made headline, so
     * this reconstructs the site's own card badge text directly from the
     * real `descuento`/`cuotas`/`promoDosPorUno` fields.
     */
    private function buildTitle(?float $cashback, ?int $installments, bool $isDosPorUno, ?string $resumen, string $merchantName): string
    {
        $parts = [];

        if ($cashback !== null) {
            $parts[] = $this->formatNumber($cashback).'% de descuento';
        }

        if ($installments !== null) {
            $parts[] = $installments.' cuotas sin interés';
        }

        if ($isDosPorUno) {
            $parts[] = '2x1';
        }

        if ($parts !== []) {
            return implode(' y ', $parts);
        }

        return $resumen ?? $merchantName;
    }

    /**
     * `dias` is a 7-char positional bitmask, index 0 = Monday (see
     * `DAY_POSITIONS` and the class docblock).
     *
     * @return string[]
     */
    private function resolveValidDays(mixed $dias): array
    {
        if (! is_string($dias) || strlen($dias) !== 7) {
            return [];
        }

        $days = [];

        foreach (self::DAY_POSITIONS as $index => $label) {
            if (($dias[$index] ?? '-') !== '-') {
                $days[] = $label;
            }
        }

        return count($days) === 7 ? ['Todos los días'] : $days;
    }

    /**
     * @return string[]
     */
    private function resolvePaymentMethods(mixed $mediosPago): array
    {
        if (! is_array($mediosPago)) {
            return [];
        }

        $names = [];

        foreach ($mediosPago as $medio) {
            if (is_array($medio)) {
                $name = $this->stringOrNull($medio['nombre'] ?? null);

                if ($name !== null) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * See the class docblock: `direccion` is a single pre-formatted string
     * in an inconsistent shape across promos — kept as-is in `address`
     * rather than guessed apart into city/province.
     *
     * @return PromotionLocationDTO[]
     */
    private function resolveLocations(mixed $sucursales): array
    {
        if (! is_array($sucursales)) {
            return [];
        }

        $locations = [];

        foreach ($sucursales as $sucursal) {
            if (! is_array($sucursal)) {
                continue;
            }

            $address = $this->stringOrNull($sucursal['direccion'] ?? null);

            if ($address === null) {
                continue;
            }

            $locations[] = new PromotionLocationDTO(
                scope: 'store',
                address: $address,
                latitude: is_numeric($sucursal['latitud'] ?? null) ? (float) $sucursal['latitud'] : null,
                longitude: is_numeric($sucursal['longitud'] ?? null) ? (float) $sucursal['longitud'] : null,
            );
        }

        return $locations;
    }

    /**
     * `legales` is sometimes plain text, sometimes a full HTML document
     * (confirmed live, both shapes seen across different promos) — strips
     * tags either way, `strip_tags`/`html_entity_decode` on already-plain
     * text is a no-op.
     */
    private function htmlToPlainText(string $html): string
    {
        $html = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h2>', '</h3>'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;
        $lines = array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== '');

        return trim(implode("\n", $lines));
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
    }

    private function positiveFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
