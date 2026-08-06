<?php

namespace App\Scrapers\Supervielle;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Models\Merchant;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use RuntimeException;
use Throwable;

/**
 * supervielle.com.ar/personas/beneficios/descuentos/{rubro} is a Next.js
 * (Pages Router) site — confirmed live via `__NEXT_DATA__`'s own
 * `page: "BeneficiosBuscador"` and the downloaded `pages/BeneficiosBuscador.js`
 * bundle. That bundle's own `getInitialProps` (confirmed live by downloading
 * and grepping it, no source map needed) calls a genuinely public,
 * same-origin, unauthenticated JSON API — `runtimeConfig.API_BENEFICIOS` in
 * the page's own `__NEXT_DATA__` resolves to
 * `https://www.supervielle.com.ar/api/beneficios` — so this scraper talks to
 * that API directly instead of scraping HTML. No WAF/bot-detection was
 * encountered on this host (confirmed live: plain `curl` with no special
 * headers gets instant real data), only a CloudFront-fronted Express server.
 *
 * The task's own reference URL is confirmed to be just ONE of many `rubro`
 * (category) tabs, and — a second, less obvious axis discovered while
 * reverse-engineering the bundle — the site also has a "Clásico" vs.
 * "Identité" client-type toggle (`esIdentite=false`/`true`, a public radio
 * button rendered directly on the same page, no login involved) that changes
 * which rubros and promotions exist. Both axes are walked to reach the
 * complete catalog:
 *
 * - `GET /api/rubros?esIdentite={false|true}` (confirmed live via the same
 *   bundle's `getInitialProps`, function `y.k`) returns that segment's own
 *   rubro taxonomy: 20 rubros for "Clásico" (confirmed live: Automotor,
 *   Belleza, Carnicerias Mendoza, Carnicerias San Luis, Combustible, Compras,
 *   Dia del Nino, Entretenimiento, Farmacia, Hogar, Indumentaria, Invierno en
 *   Chile, Mascotas, Mercado Libre, Opticas, Promos Visa, Supermercados,
 *   Tecnologia, Transporte, Turismo), 23 for "Identité" (the same 20 plus
 *   Bodegas, Invierno en Cerro Castor, and Restaurantes — confirmed live,
 *   these three simply don't exist under "Clásico" at all).
 * - `GET /api/beneficios?rubro={nombre}&esIdentite={false|true}` (confirmed
 *   live via the same bundle, function `D`) returns EVERY promotion for that
 *   one rubro+segment pair in a single response — no pagination parameter
 *   exists on this endpoint at all (confirmed live: Farmacia alone returned
 *   83 items in one shot with no `page`/`limit`/`hasMore` in the response
 *   shape), so stage 1 here is one flat request per pair, never a page walk.
 *   `rubro` is REQUIRED (confirmed live: omitting it returns 0 results, not
 *   the full catalog) and must be the segment's own `nombre` string
 *   (`esIdentite` alone, with no `rubro`, also returns 0 results) — so this
 *   scraper fetches each segment's own rubro list first, then walks exactly
 *   those (rubro, segment) pairs: 20 + 23 = 43 listing calls confirmed live to
 *   cover the entire catalog. Like every other wallet's own stage 1 in this
 *   project, none of these 43 calls are best-effort — a failure on any single
 *   one aborts the rest of the sweep rather than silently under-reporting the
 *   catalog.
 *
 * IMPORTANT — confirmed live, the "Identité" segment is NOT simply a
 * different, disjoint catalog: for the 20 rubros both segments share, every
 * "Identité" listing entry sampled is a byte-for-byte duplicate of its
 * "Clásico" counterpart (same `marca`/`dias`/`cuotas`/`descuento`/`tope`/
 * validity dates/etc.) EXCEPT for `id` and the `esIdentite` flag itself — the
 * same real-world promotion simply gets a second, separate id in the CMS for
 * each segment's own feed. Two confirmed-live exceptions to that "identical
 * mirror" rule: `Supermercados` under "Identité" is missing the 12 "Plan
 * Sueldo"-only Clásico promotions (a payroll-account sub-requirement that
 * doesn't apply to Identité clients), and `Turismo`/`Carnicerias Mendoza`/
 * `Carnicerias San Luis` each have a handful of promotions that only exist
 * under one segment or the other even though the rubro name itself is shared.
 * Yielding both copies of every mirrored promotion would double-count ~150
 * items that look identical to an end user. So this scraper computes a
 * content signature per listing item (every field except `id`/`esIdentite`,
 * order-independent) and skips an item whose signature was already yielded —
 * "Clásico" is walked first so it wins the dedup for shared promotions,
 * "Identité" only ever contributes what "Clásico" genuinely lacks (the three
 * exclusive rubros, the Turismo/Carnicerias extras, etc.). Confirmed live on
 * the full catalog: 497 raw listing rows across all 43 pairs collapse to 293
 * unique promotions this way.
 *
 * Each listing entry already carries the discount mechanics (`descuento`, a
 * string like `"20%"`; `cuotas`, a one-element array like `["12"]` — despite
 * the plural name, confirmed live this is always a single max-installments
 * value, never a list of options), a per-weekday string list (`dias`,
 * confirmed live as full day names with a stray leading space on every
 * element but the first, e.g. `["lunes"," martes",...]` — trimmed here), a
 * reimbursement cap (`tope`, a plain number, present on ~45% of promotions
 * sampled live), two boolean card flags (`esTarjetaCredito`/`esTarjetaDebito`
 * — confirmed live both `false` at once only for a MODO-QR-only promo with no
 * underlying card product), the category name directly (`rubro`, no id/slug
 * lookup needed), and real ISO validity dates (`fechaVigenciaDesde`/
 * `fechaVigenciaHasta`) already on the listing — unlike Banco Ciudad/Galicia,
 * no detail call is needed just to get dates.
 *
 * Stage 2 (double scraping) is still genuinely needed, just for different
 * data than dates: `GET /api/beneficio?id={id}` (confirmed live via the same
 * bundle) returns the one field the listing never carries at all — `legales`,
 * the full promo-specific Términos y Condiciones — confirmed live present on
 * all 293 catalog promotions sampled. It also occasionally carries a merchant
 * link (`web`, ~51% of the time, sometimes the merchant's own site, sometimes
 * a T&C PDF hosted on the bank's CMS, sometimes a MODO/Visa promo landing
 * page — no consistent shape, and no field in `PromotionDTO` fits a
 * "secondary link", so it's left in `rawPayload` only, the same treatment
 * Banco Ciudad gives `comercio.web`) and a `cft`/`locales` pair that were
 * `null`/`[]` on all 293 promotions sampled live — real fields, just unused
 * by this catalog in practice, so `locations` is always `[]` here (an
 * accepted limitation, not a bug: this catalog simply has no physical-branch
 * data anywhere, confirmed by sampling the full deduplicated catalog, not
 * just the reference category). An invalid/missing id returns HTTP 200 with
 * `{"codigo":"NOK",...}` rather than an HTTP error or 404 (confirmed live),
 * so failure detection checks `codigo` rather than relying on `.throw()`
 * alone. Like every other wallet's own stage 2 in this project, this is
 * best-effort: a failure (network error or a non-"OK" `codigo`) simply leaves
 * the promo with no `terms` and the listing's own `descuento` defaulting to
 * `discountPercentage` (see below), it never drops the promo or aborts the
 * scrape.
 *
 * `descuento` is NOT uniformly one mechanic in this catalog, unlike every
 * other wallet documented so far in this project — confirmed live with an
 * explicit counter-example either way: a Visa ski-season promo's own
 * `legales` says "El descuento se aplica automáticamente al momento del
 * pago" (an instant checkout discount), while same-shaped MODO butcher-shop
 * promos say "Se otorgará un descuento vía reintegro del 20%... El reintegro
 * se verá reflejado en la cuenta asociada a MODO dentro de los 30 días" (a
 * reimbursement credited later). Sampling the full 132-promotion `descuento`
 * subset live: 93 explicitly say "reintegro" somewhere in `legales`, the
 * remaining 39 (mostly "con Visa" branded, confirmed live 17/19 of those
 * explicitly say the discount applies automatically at payment) never do, and
 * zero say both. So classification is per-promotion, not catalog-wide: stage
 * 2's own `legales` text containing "reintegro" (case-insensitive) maps
 * `descuento` to `cashbackPercentage`; anything else (including the ~30
 * promotions where stage 2 legitimately failed, since 0/293 sampled live were
 * ever missing `legales` when stage 2 succeeded) maps it to
 * `discountPercentage` instead, matching the literal field name and what a
 * Visa-branded promo's own text says is the default mechanic absent evidence
 * otherwise. `title` still renders the site's own literal wording ("X% de
 * descuento"/"X% de reintegro" to match whichever was detected, plus "N
 * cuotas sin interés" when `cuotas` is set) since that's what a user actually
 * sees, falling back to `merchantName` when neither is present (e.g. a
 * pure-installments promo with no `descuento` at all).
 *
 * Categories (`rubro`): 23 distinct names confirmed live (the union listed
 * above), Spanish, inconsistent accenting (`"Dia del Nino"`, `"Opticas"` — no
 * tildes at all, straight from the source, not a scraping artifact). Two
 * already collide with an existing canonical category under the exact same
 * alias key already in `config/category_aliases.php` (`'belleza' =>
 * 'Salud y Belleza'`, and this catalog's own `"Supermercados"`/`"Transporte"`/
 * `"Combustible"`/`"Mascotas"` rubro names are themselves already the
 * canonical name) — not edited here per this task's own scope, just noted for
 * whoever adds Supervielle-specific aliases later. `"Hogar"`,
 * `"Indumentaria"`, `"Farmacia"`, `"Tecnologia"`, and `"Turismo"` are clearly
 * the same concept as existing canonical categories reached via a
 * near-but-not-exact alias key (`hogar-y-decoracion`, `indumentaria`,
 * `farmacias-y-salud`, `electrodomesticos-y-tecnologia`, `turismo`/
 * `turismo-y-viajes`) but aren't an exact key match themselves, so they won't
 * auto-resolve — left for a future, separately-verified alias entry rather
 * than guessed here. The rest (`Bodegas`, `Carnicerias Mendoza`, `Carnicerias
 * San Luis`, `Compras`, `Dia del Nino`, `Entretenimiento`, `Invierno en Cerro
 * Castor`, `Invierno en Chile`, `Mercado Libre`, `Opticas`, `Promos Visa`,
 * `Restaurantes`) are specific/seasonal enough that they don't obviously
 * collide with anything already in that file.
 *
 * `minimumPurchase` has no structured field at all (only occasional free-text
 * mentions in `legales`, e.g. "Monto mínimo de compra $50.000", in wildly
 * inconsistent phrasing) — left null, an accepted limitation in the same
 * spirit as Credicoop's. `zonas`/`esDestacado` exist on every listing item but
 * were empty string / `false` on all 293 promotions sampled live — no real
 * signal to map anywhere.
 */
class SupervielleScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string RUBROS_URL = 'https://www.supervielle.com.ar/api/rubros';

    private const string BENEFICIOS_URL = 'https://www.supervielle.com.ar/api/beneficios';

    private const string BENEFICIO_URL = 'https://www.supervielle.com.ar/api/beneficio';

    private const string DETAIL_PAGE_BASE = 'https://www.supervielle.com.ar/personas/beneficios/detalle/';

    /**
     * See the class docblock: "Clásico" is walked first on purpose so it wins
     * the content-signature dedup for every promotion mirrored into
     * "Identité" — "Identité" then only ever contributes what "Clásico"
     * genuinely lacks.
     *
     * @var array<string, bool>
     */
    private const array SEGMENTS = [
        'clasico' => false,
        'identite' => true,
    ];

    /**
     * `dias` entries are full Spanish day names (confirmed live, with a
     * stray leading space on every element but the first) rather than a
     * positional bitmask like Banco Ciudad's — trimmed and title-cased here.
     */
    private const int FULL_WEEK_DAY_COUNT = 7;

    public function walletSlug(): string
    {
        return 'supervielle';
    }

    public function scrape(): iterable
    {
        $seenSignatures = [];

        foreach (self::SEGMENTS as $esIdentite) {
            foreach ($this->fetchRubros($esIdentite) as $rubro) {
                foreach ($this->fetchListing($rubro, $esIdentite) as $item) {
                    $signature = $this->contentSignature($item);

                    if (isset($seenSignatures[$signature])) {
                        continue;
                    }

                    $seenSignatures[$signature] = true;

                    $dto = $this->buildDto($item);

                    if ($dto !== null) {
                        yield $dto;
                    }
                }
            }
        }
    }

    /**
     * Stage 1a: this segment's own rubro taxonomy. Deliberately not
     * best-effort — see the class docblock, the same as every other wallet's
     * own stage 1 in this project.
     *
     * @return string[]
     */
    private function fetchRubros(bool $esIdentite): array
    {
        $response = $this->http()
            ->get(self::RUBROS_URL, ['esIdentite' => $esIdentite ? 'true' : 'false'])
            ->throw()
            ->json();

        if (! is_array($response) || ($response['codigo'] ?? null) !== 'OK') {
            throw new RuntimeException('Supervielle: unexpected rubros response: '.json_encode($response));
        }

        $rubros = is_array($response['rubros'] ?? null) ? $response['rubros'] : [];
        $names = [];

        foreach ($rubros as $rubro) {
            $name = is_array($rubro) ? $this->stringOrNull($rubro['nombre'] ?? null) : null;

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Stage 1b: every promotion for one (rubro, segment) pair, in a single
     * unpaginated response (see the class docblock). Deliberately not
     * best-effort, matching `fetchRubros()` above.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function fetchListing(string $rubro, bool $esIdentite): iterable
    {
        $response = $this->http()
            ->get(self::BENEFICIOS_URL, [
                'rubro' => $rubro,
                'esIdentite' => $esIdentite ? 'true' : 'false',
            ])
            ->throw()
            ->json();

        if (! is_array($response) || ($response['codigo'] ?? null) !== 'OK') {
            throw new RuntimeException('Supervielle: unexpected beneficios response: '.json_encode($response));
        }

        $items = is_array($response['beneficios'] ?? null) ? $response['beneficios'] : [];

        foreach ($items as $item) {
            if (is_array($item)) {
                yield $item;
            }
        }
    }

    /**
     * See the class docblock: every field except `id`/`esIdentite`, so a
     * promotion mirrored identically into both segments collapses to one
     * signature regardless of which segment it's read from.
     *
     * @param  array<string, mixed>  $item
     */
    private function contentSignature(array $item): string
    {
        unset($item['id'], $item['esIdentite']);
        ksort($item);

        return json_encode($item) ?: serialize($item);
    }

    /**
     * Stage 2 enrichment for a single promo. Never throws — any failure
     * (network error, or the confirmed-live `{"codigo":"NOK"}` shape for an
     * invalid/missing id) simply returns `null`, and the caller falls back
     * to the listing's own fields.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDetail(string $id): ?array
    {
        try {
            $response = $this->http()
                ->get(self::BENEFICIO_URL, ['id' => $id])
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($response) || ($response['codigo'] ?? null) !== 'OK') {
            return null;
        }

        return is_array($response['beneficio'] ?? null) ? $response['beneficio'] : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function buildDto(array $item): ?PromotionDTO
    {
        $rawMerchantName = $this->stringOrNull($item['marca'] ?? null);
        $id = $this->stringOrNull($item['id'] ?? null);

        if ($rawMerchantName === null || $id === null) {
            return null;
        }

        // `$rawMerchantName` (with the suffix) is kept around for
        // `resolvePaymentMethods()` below — it's the only signal that a
        // promo requires paying via MODO specifically. `$merchantName`
        // (the merchant's actual identity) never carries it, nor the
        // "con Visa débito/Signature NFC" payment-method qualifier (see
        // `Merchant::stripVisaSuffix()`), nor a customer-segment qualifier
        // like "- Jubilados" (see `Merchant::stripSegmentQualifierSuffix()`).
        $merchantName = Merchant::stripSegmentQualifierSuffix(
            Merchant::stripVisaSuffix(Merchant::stripModoSuffix($rawMerchantName))
        );

        $detail = $this->fetchDetail($id);
        $legales = $this->stringOrNull($detail['legales'] ?? null);
        $terms = $legales !== null ? $this->cleanText($legales) : null;

        $percentage = $this->parsePercentage($item['descuento'] ?? null);
        [$discountPercentage, $cashbackPercentage] = $this->classifyPercentage($percentage, $legales);

        $installments = $this->positiveIntOrNull($item['cuotas'][0] ?? null);
        $tope = $this->positiveFloatOrNull($item['tope'] ?? null) ?? $this->positiveFloatOrNull($detail['tope'] ?? null);

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $this->buildTitle($discountPercentage, $cashbackPercentage, $installments, $merchantName),
            merchantIconUrl: $this->stringOrNull($item['logo'] ?? null),
            category: $this->stringOrNull($item['rubro'] ?? null),
            discountPercentage: $discountPercentage,
            cashbackPercentage: $cashbackPercentage,
            installments: $installments,
            reimbursementCap: $tope,
            validDays: $this->resolveValidDays($item['dias'] ?? null),
            startDate: $this->parseIsoDate($this->stringOrNull($item['fechaVigenciaDesde'] ?? null)),
            endDate: $this->parseIsoDate($this->stringOrNull($item['fechaVigenciaHasta'] ?? null)),
            terms: $terms,
            url: self::DETAIL_PAGE_BASE.$id,
            externalId: $id,
            paymentMethods: $this->resolvePaymentMethods($item, $rawMerchantName),
            locations: [],
            rawPayload: $detail ?? $item,
        );
    }

    /**
     * See the class docblock: neither call has one ready-made headline, so
     * this reconstructs the site's own card badge text directly from the
     * real `descuento`/`cuotas` fields, wording it as "reintegro" when
     * stage 2's own `legales` confirmed that mechanic.
     */
    private function buildTitle(?float $discountPercentage, ?float $cashbackPercentage, ?int $installments, string $merchantName): string
    {
        $parts = [];

        if ($discountPercentage !== null) {
            $parts[] = $this->formatNumber($discountPercentage).'% de descuento';
        } elseif ($cashbackPercentage !== null) {
            $parts[] = $this->formatNumber($cashbackPercentage).'% de reintegro';
        }

        if ($installments !== null) {
            $parts[] = $installments.' cuotas sin interés';
        }

        return $parts !== [] ? implode(' y ', $parts) : $merchantName;
    }

    /**
     * See the class docblock: `descuento` is a checkout discount unless
     * stage 2's own `legales` explicitly says it's a reintegro (a
     * reimbursement credited later) — confirmed live to genuinely vary
     * promo-by-promo in this catalog, unlike every other wallet documented so
     * far in this project.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function classifyPercentage(?float $percentage, ?string $legales): array
    {
        if ($percentage === null) {
            return [null, null];
        }

        if ($legales !== null && mb_stripos($legales, 'reintegro') !== false) {
            return [null, $percentage];
        }

        return [$percentage, null];
    }

    /**
     * `descuento` is a string like `"20%"` — confirmed live always a plain
     * integer percentage, but parsed with a decimal-tolerant regex rather
     * than a bare `rtrim` in case a future promotion uses one.
     */
    private function parsePercentage(mixed $descuento): ?float
    {
        if (! is_string($descuento) || $descuento === '') {
            return null;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)/', $descuento, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    /**
     * See the class docblock: full day names with a stray leading space on
     * every element but the first (confirmed live) — trimmed and
     * title-cased (the first letter is always a plain ASCII character on
     * every Spanish day name, so `ucfirst` is safe even on accented names
     * like "miércoles").
     *
     * @return string[]
     */
    private function resolveValidDays(mixed $dias): array
    {
        if (! is_array($dias)) {
            return [];
        }

        $days = [];

        foreach ($dias as $dia) {
            $trimmed = is_string($dia) ? trim($dia) : '';

            if ($trimmed !== '') {
                $days[] = ucfirst($trimmed);
            }
        }

        return count($days) === self::FULL_WEEK_DAY_COUNT ? ['Todos los días'] : $days;
    }

    /**
     * See the class docblock: no structured card-brand field exists, only
     * two generic card-product flags plus the merchant name's own "con MODO"
     * suffix (confirmed live as the only structured signal that payment goes
     * through MODO's QR rails rather than a card swipe).
     *
     * @param  array<string, mixed>  $item
     * @return string[]
     */
    private function resolvePaymentMethods(array $item, string $merchantName): array
    {
        $methods = [];

        if ($item['esTarjetaCredito'] ?? false) {
            $methods[] = 'Tarjeta de crédito';
        }

        if ($item['esTarjetaDebito'] ?? false) {
            $methods[] = 'Tarjeta de débito';
        }

        if (mb_stripos($merchantName, 'MODO') !== false) {
            $methods[] = 'MODO';
        }

        return $methods;
    }

    /**
     * `legales` was plain text on every promotion sampled live (no HTML
     * tags observed), but entities/stray whitespace are still normalized
     * defensively rather than assumed clean.
     */
    private function cleanText(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
