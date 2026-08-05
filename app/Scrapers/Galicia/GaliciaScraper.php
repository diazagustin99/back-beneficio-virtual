<?php

namespace App\Scrapers\Galicia;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\DTOs\PromotionLocationDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * galicia.ar/personas/buscador-de-promociones itself is server-rendered AEM
 * (Adobe Experience Manager) markup with no promotions in the HTML — the
 * whole widget is a same-site `<iframe src="https://beneficios.galicia.ar/">`
 * (confirmed via the page's own `.model.json` AEM page model). That iframe
 * target is in turn a Next.js SPA ("spa-gros-loyalty-web", per its own
 * `/conf/env-config.js`) that is statically exported and ships an empty
 * shell — a bare fetch of that host never returns promotion data, it only
 * ever returns the same ~5KB HTML skeleton regardless of path or query.
 *
 * The SPA's own webpack source map (`/_next/static/chunks/pages/index-*.js.map`,
 * publicly served alongside the bundle) reveals the real data source: at
 * runtime (`window.ENV='latest'`) the bundle resolves its API host from a
 * hardcoded map to `https://loyalty.bff.bancogalicia.com.ar` — a completely
 * different domain, genuinely public with no auth (confirmed live: no
 * cookies, no API key, not even the cosmetic `id_canal: Quiero` header the
 * real app sends is actually required — no WAF/bot-challenge encountered).
 *
 * Stage 1 walks `/api/portal/personalizacion/v1/promociones/catalogo`
 * (paginated via `page`/`pageSize`), the exact endpoint the public
 * buscador's own unfiltered results grid calls. Confirmed live at ~1750-1800
 * promotions, all `tipoPromocion: "Marca"`. Each listing entry already
 * carries a ready-made display string (`promocion`, e.g. "15% de ahorro y
 * hasta 3 cuotas sin interés" or "Hasta 6 cuotas sin interés" — confirmed to
 * be exactly what the site itself renders as the card's headline), the
 * accepted cards (`mediosDePago`), and a category label (`subtitulo`) —
 * richer than most wallets' listing stage in this project.
 *
 * Stage 2 enriches each promo two ways, both confirmed live via the site's
 * own detail page (`beneficios.galicia.ar/promocion/{id}`):
 *  - `/catalogo/v1/promociones/idPromocion/{id}` for the real validity
 *    window (`fechaDesde`/`fechaHasta`, DD/MM/YYYY — a different format than
 *    the listing's own ISO `fechaHasta`), the full legal text (`legales`),
 *    and the reimbursement cap / minimum purchase. Confirmed live to
 *    occasionally return a 404 for an id that the very listing call just
 *    returned it in (an eventual-consistency quirk between the listing and
 *    detail services — reproduced live by simply retrying the same id a few
 *    seconds later and getting a normal 200) — so, exactly like every other
 *    wallet's stage 2 in this project, this is best-effort: a failure only
 *    leaves those fields null (with a couple of them recovered from the
 *    listing itself, see below), it never aborts the scrape.
 *  - `/catalogo/v1/locales/idPromocion/{id}` (also paginated) for physical
 *    store addresses with real lat/long — confirmed live to return up to
 *    170 branches for a single nationwide chain (Supermercados La Anonima).
 *    Only fetched when stage 2's detail call succeeded and reported
 *    `tiendaFisica: true`, to avoid a wasted call for the many online-only
 *    promos.
 *
 * `porcentajeAhorro` is a cashback-style reintegro credited days later to
 * the customer's own savings account, not an at-checkout discount: the
 * site's own hardcoded fallback copy for any promo with this field set reads
 * "Vas a ver el reintegro en tu caja de ahorro en hasta 72 horas hábiles",
 * and real `legales` text confirms the mechanics ("El importe ahorrado se
 * acreditará ... en la caja de ahorro común") — so it is mapped to
 * `cashbackPercentage`, never `discountPercentage`, mirroring the same
 * reintegro-vs-descuento distinction already used for ICBC/Prex/Mercado
 * Pago/Ualá elsewhere in this project. No promo observed in this catalog
 * exposes a genuine at-checkout discount.
 *
 * No description-worthy field exists on either call (`descripcionAdicional`
 * is a rare special-tag string like "EXCLUSIVA QR", not prose; `leyendaCompra`
 * is constant boilerplate identical across every promo observed) —
 * `description` stays null, same accepted limitation already documented for
 * Mercado Pago/Prex's scrapers.
 */
class GaliciaScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string API_BASE = 'https://loyalty.bff.bancogalicia.com.ar/api/portal/';

    private const string IMAGE_BASE_URL = 'https://www.galicia.ar/content/dam/galicia/banco-galicia/personas/promociones/catalogo-de-beneficios/';

    private const string PROMO_URL_BASE = 'https://beneficios.galicia.ar/promocion/';

    private const int PAGE_SIZE = 50;

    /**
     * Safety cap on listing pages so a pagination bug can't turn this into
     * an unbounded loop. The catalog observed live is ~1750-1800
     * promotions / 50 per page ≈ 36 pages.
     */
    private const int MAX_LISTING_PAGES = 80;

    private const int LOCATIONS_PAGE_SIZE = 50;

    /**
     * Safety cap on location pages per promo. The largest chain observed
     * live (Supermercados La Anonima) had 170 branches ≈ 4 pages at 50/page.
     */
    private const int MAX_LOCATION_PAGES = 20;

    /**
     * The detail call's `diasAplicacion` day codes, confirmed live
     * (e.g. "Lu;Ma;Mi;Ju;Vi;Sa;Do") — the same 2-letter codes the site's own
     * `DAYS` filter config uses.
     *
     * @var array<string, string>
     */
    private const array DAY_CODES = [
        'Lu' => 'Lunes', 'Ma' => 'Martes', 'Mi' => 'Miércoles', 'Ju' => 'Jueves',
        'Vi' => 'Viernes', 'Sa' => 'Sábado', 'Do' => 'Domingo',
    ];

    public function walletSlug(): string
    {
        return 'galicia';
    }

    public function scrape(): iterable
    {
        foreach ($this->fetchListing() as $item) {
            $id = $item['id'] ?? null;

            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $id = (string) $id;
            $detail = $this->fetchDetail($id);
            $locations = ($detail['tiendaFisica'] ?? false) === true
                ? $this->fetchLocations($id)
                : [];

            $dto = $this->buildDto($id, $item, $detail, $locations);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function fetchListing(): iterable
    {
        $page = 1;

        do {
            $response = $this->http()
                ->withHeaders(['id_canal' => 'Quiero', 'Accept' => 'application/json'])
                ->get(self::API_BASE.'personalizacion/v1/promociones/catalogo', [
                    'page' => $page,
                    'pageSize' => self::PAGE_SIZE,
                ])
                ->throw()
                ->json();

            $list = is_array($response['data']['list'] ?? null) ? $response['data']['list'] : [];

            foreach ($list as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $page++;
        } while (count($list) > 0 && $page <= self::MAX_LISTING_PAGES);
    }

    /**
     * Stage 2a enrichment for a single promo. Never throws — any failure
     * (network error, transient 404) yields `null`, and the caller falls
     * back to the listing's own fields. See the class docblock for why a
     * detail 404 is expected here, not exceptional.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDetail(string $id): ?array
    {
        try {
            $response = $this->http()
                ->withHeaders(['id_canal' => 'Quiero', 'Accept' => 'application/json'])
                ->get(self::API_BASE.'catalogo/v1/promociones/idPromocion/'.$id)
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        $data = $response['data'] ?? null;

        return is_array($data) && isset($data['id']) ? $data : null;
    }

    /**
     * Stage 2b enrichment for a single promo — only called when stage 2a
     * reported `tiendaFisica: true`. Never throws — any failure simply
     * leaves the promo without locations.
     *
     * @return PromotionLocationDTO[]
     */
    private function fetchLocations(string $id): array
    {
        $locations = [];
        $page = 1;

        do {
            try {
                $response = $this->http()
                    ->withHeaders(['id_canal' => 'Quiero', 'Accept' => 'application/json'])
                    ->get(self::API_BASE.'catalogo/v1/locales/idPromocion/'.$id, [
                        'page' => $page,
                        'pageSize' => self::LOCATIONS_PAGE_SIZE,
                    ])
                    ->throw()
                    ->json();
            } catch (Throwable) {
                break;
            }

            $list = is_array($response['data']['list'] ?? null) ? $response['data']['list'] : [];

            foreach ($list as $entry) {
                if (is_array($entry)) {
                    $locations[] = $this->toLocationDto($entry);
                }
            }

            $page++;
        } while (count($list) > 0 && $page <= self::MAX_LOCATION_PAGES);

        return array_values(array_filter($locations));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toLocationDto(array $entry): ?PromotionLocationDTO
    {
        $street = $this->stringOrNull($entry['calle'] ?? null);
        $city = $this->stringOrNull($entry['localidadNombre'] ?? null);

        if ($street === null && $city === null) {
            return null;
        }

        $number = isset($entry['numero']) && is_numeric($entry['numero']) ? (string) $entry['numero'] : null;
        $address = $street !== null && $number !== null ? trim($street.' '.$number) : $street;

        // `aclaracion` (e.g. "Teatro Coliseo") names the specific venue when
        // one merchant/brand has several; it's a more useful storeName than
        // the repeated umbrella brand name (`nombreMarca`) when present.
        $storeName = $this->stringOrNull($entry['aclaracion'] ?? null) ?? $this->stringOrNull($entry['nombreMarca'] ?? null);

        return new PromotionLocationDTO(
            scope: 'store',
            province: $this->stringOrNull($entry['provinciaNombre'] ?? null),
            city: $city,
            address: $address,
            storeName: $storeName,
            latitude: is_numeric($entry['latitud'] ?? null) ? (float) $entry['latitud'] : null,
            longitude: is_numeric($entry['longitud'] ?? null) ? (float) $entry['longitud'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     * @param  PromotionLocationDTO[]  $locations
     */
    private function buildDto(string $id, array $listing, ?array $detail, array $locations): ?PromotionDTO
    {
        $marca = is_array($detail['marca'] ?? null) ? $detail['marca'] : null;

        $merchantName = $this->stringOrNull($marca['nombre'] ?? null) ?? $this->stringOrNull($listing['titulo'] ?? null);

        if ($merchantName === null) {
            return null;
        }

        $title = $this->stringOrNull($listing['promocion'] ?? null) ?? $merchantName;
        $imagen = $this->stringOrNull($marca['imagen'] ?? null) ?? $this->stringOrNull($listing['imagen'] ?? null);

        $categoriaDetail = is_array($marca['categoria'] ?? null) ? $marca['categoria'] : null;
        $category = $this->stringOrNull($categoriaDetail['descripcion'] ?? null) ?? $this->stringOrNull($listing['subtitulo'] ?? null);

        [$cashback, $installments] = $this->resolveCashbackAndInstallments($listing, $detail);

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $title,
            merchantIconUrl: $imagen !== null ? self::IMAGE_BASE_URL.$imagen : null,
            category: $category,
            description: null, // No reliable description field on either call — see class docblock.
            cashbackPercentage: $cashback,
            installments: $installments,
            reimbursementCap: $this->positiveFloatOrNull($detail['topeReintegro'] ?? null),
            minimumPurchase: $this->positiveFloatOrNull($detail['minimoCompra'] ?? null),
            validDays: $this->resolveValidDays($detail),
            startDate: $this->parseGaliciaDate($this->stringOrNull($detail['fechaDesde'] ?? null)),
            endDate: $this->resolveEndDate($listing, $detail),
            terms: $this->stringOrNull($detail['legales'] ?? null),
            url: self::PROMO_URL_BASE.$id,
            externalId: $id,
            paymentMethods: $this->resolvePaymentMethods($listing, $detail),
            locations: $locations,
            rawPayload: $detail ?? $listing,
        );
    }

    /**
     * `porcentajeAhorro`/`cuotaSinInteresHasta` only exist on the detail
     * call; when it fails, both are recovered from the listing's own
     * pre-rendered `promocion` string (e.g. "15% de ahorro y hasta 3 cuotas
     * sin interés", confirmed live to be exactly the text the site itself
     * builds from these two numbers) via regex, instead of losing them
     * entirely.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     * @return array{0: ?float, 1: ?int}
     */
    private function resolveCashbackAndInstallments(array $listing, ?array $detail): array
    {
        $text = $this->stringOrNull($listing['promocion'] ?? null) ?? '';

        $cashback = is_numeric($detail['porcentajeAhorro'] ?? null) && (float) $detail['porcentajeAhorro'] > 0
            ? (float) $detail['porcentajeAhorro']
            : $this->extractPercentage($text);

        $installments = is_numeric($detail['cuotaSinInteresHasta'] ?? null)
            ? (int) $detail['cuotaSinInteresHasta']
            : $this->extractInstallments($text);

        return [$cashback, $installments];
    }

    private function extractPercentage(string $text): ?float
    {
        return preg_match('/(\d+(?:[.,]\d+)?)\s*%\s*de ahorro/iu', $text, $matches) === 1
            ? (float) str_replace(',', '.', $matches[1])
            : null;
    }

    private function extractInstallments(string $text): ?int
    {
        return preg_match('/hasta\s+(\d+)\s+cuotas/iu', $text, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /**
     * `diasAplicacion` only exists on the detail call, as semicolon-separated
     * 2-letter codes. The listing's own `leyendaDiasAplicacion` is free text
     * that can be a range (e.g. "Lunes a Viernes", "Martes a Domingo",
     * confirmed live) rather than a simple list, so it isn't reliably
     * splittable — this is left empty rather than guessed when detail is
     * unavailable.
     *
     * @param  array<string, mixed>|null  $detail
     * @return string[]
     */
    private function resolveValidDays(?array $detail): array
    {
        $raw = $this->stringOrNull($detail['diasAplicacion'] ?? null);

        if ($raw === null) {
            return [];
        }

        $codes = array_filter(array_map('trim', explode(';', $raw)), fn ($code) => $code !== '');
        $days = array_values(array_filter(array_map(fn ($code) => self::DAY_CODES[$code] ?? null, $codes)));

        return count($days) === 7 ? ['Todos los días'] : $days;
    }

    /**
     * The detail call's `fechaHasta` (DD/MM/YYYY) is the authoritative
     * value; the listing's own `fechaHasta` (ISO-8601, confirmed live — a
     * different format than detail's for the same concept) is used only
     * when detail is unavailable, so an end date survives either way.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     */
    private function resolveEndDate(array $listing, ?array $detail): ?DateTimeInterface
    {
        $fromDetail = $this->parseGaliciaDate($this->stringOrNull($detail['fechaHasta'] ?? null));

        return $fromDetail ?? $this->parseIsoDate($this->stringOrNull($listing['fechaHasta'] ?? null));
    }

    /**
     * Galicia's own DD/MM/YYYY validity dates (`fechaDesde`/`fechaHasta` on
     * the detail call) — not covered by the shared ParsesForeignDates trait,
     * whose helpers are ISO/.NET-wire/free-text Spanish, not this numeric
     * slash format.
     */
    private function parseGaliciaDate(?string $value): ?DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('d/m/Y', $value);
        } catch (Throwable) {
            return null;
        }

        return $date !== false ? $date->startOfDay() : null;
    }

    /**
     * Both the listing and detail expose the same `mediosDePago` shape
     * (`[{tarjeta, tipoTarjeta}]`) — the listing's copy is used first since
     * it's always available, detail only as a fallback.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     * @return string[]
     */
    private function resolvePaymentMethods(array $listing, ?array $detail): array
    {
        $fromListing = is_array($listing['mediosDePago'] ?? null) ? $listing['mediosDePago'] : [];
        $methods = $fromListing !== [] ? $fromListing : (is_array($detail['mediosDePago'] ?? null) ? $detail['mediosDePago'] : []);

        $names = array_map(
            fn ($method) => is_array($method) ? $this->stringOrNull($method['tarjeta'] ?? null) : null,
            $methods
        );

        return array_values(array_unique(array_filter($names, fn ($name) => $name !== null)));
    }

    private function positiveFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
