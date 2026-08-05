<?php

namespace App\Scrapers\Credicoop;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\DTOs\PromotionLocationDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use RuntimeException;
use Throwable;

/**
 * beneficios.bancocredicoop.coop/coop/beneficios/?p=search is classic
 * server-rendered PHP (Apache/CentOS, `PHPSESSID` cookie) — the page itself
 * ships zero promotions in the initial HTML (the results grid is an empty
 * `<div class="product-grid-list">` with a loading spinner). The real data
 * source, confirmed live via the page's own `js/commons.v20.js`, is a
 * same-origin JSON endpoint the page's own search calls:
 * `POST /coop/beneficios/xapi_get_coop_active_benefits_general.php`, body
 * `{"data":{"search":{...filters,offset,limit},"csrf_token":"..."}}`. No
 * separate public API host exists — everything is same-origin PHP.
 *
 * IMPORTANT — this endpoint is session/CSRF-gated, unlike every other
 * wallet's public API in this project: confirmed live, a POST with no
 * `PHPSESSID` cookie or a stale `csrf_token` returns HTTP 200 with
 * `{"msg":"CSRF Token Error"}` instead of data. The token is embedded in the
 * listing page's own markup (`<input id="header_csrf_token" ... value="...">`)
 * and is bound to the `PHPSESSID` the same response sets via `Set-Cookie` —
 * so `bootstrapSession()` does one plain GET of the listing page first,
 * scrapes both out, and every subsequent call replays the cookie manually
 * (Laravel's HTTP client does not persist cookies across separate `http()`
 * calls on its own). No Akamai/Incapsula-style browser fingerprinting was
 * encountered — the shared trait's realistic-Chrome User-Agent works fine
 * here, this is an ordinary application-level CSRF check, not a WAF.
 *
 * Stage 1 walks that endpoint with `search:{p:"search",offset,limit}`
 * (confirmed live: `p=search` — the task's own reference URL's query param —
 * is simply echoed back and otherwise ignored; omitting every other filter
 * is what returns the complete, unfiltered catalog). Confirmed live at 4580
 * promotions across ~46 pages of 100. Each listing entry already carries
 * everything but physical store addresses: merchant (`shop_name`), a
 * ready-rendered headline (`display_name`, e.g. "10% de AHORRO y hasta 6
 * cuotas sin interés" — confirmed to be exactly what the detail page's own
 * `#beneficios_titulo` renders), a narrative `description`, the full legal
 * text (`notes`), category (`heading`), the discount mechanics
 * (`discount_percentage`/`discount_max`/`installments`), a per-weekday
 * tri-state flag set (`discount_on_monday`...`discount_on_sunday`, `-1`
 * means "applies", `0` means "doesn't" — confirmed live via the page's own
 * `get_days_of_discounts_array()`), and a same tri-state accepted-card flag
 * set (`card_cabal_credit`/`card_cabal_debit`/`card_coop_visa`/
 * `card_coop_mastercard`/`card_coop_modo`/`card_coop_only_modo`).
 *
 * Stage 2 (double scraping, and here it genuinely earns its keep): the
 * listing intentionally omits each promo's physical store list — only a
 * `retails_count` tally — for payload-size reasons (a single nationwide
 * chain observed live, "Colorshop", carries 426 branches). The exact same
 * endpoint, called instead with `search:{shop_id,benefit_id,get_retails:true}`,
 * returns that one promo again with a full `retails[]` array (street/city/
 * province/lat/lng) — confirmed live to return every branch in one shot, no
 * further pagination needed even for 426 of them. Only called when
 * `retails_count > 0`, which in practice is every promo observed live (unlike
 * Galicia/Santander, no purely-online promo with a zero count was seen) —
 * still gated on the count rather than called unconditionally, in case one
 * exists elsewhere in the catalog. `retails[]` entries with
 * `is_virtual_retail == -1` are a virtual/online placeholder row rather than
 * a real address (confirmed live via the detail page's own
 * `render_retails_list()`, which explicitly skips them with the comment
 * "Ignoro las virtuales") and are excluded from `locations` here too. Like
 * every other wallet's stage 2 in this project, this is best-effort: a
 * network failure only leaves that promo's `locations` empty, it never
 * aborts the scrape.
 *
 * All observed promos (`type` is `"PERCENTAGE"` in every one of 680 promos
 * sampled live across the catalog) are a `reintegro` — a reimbursement
 * credited to the cardholder's own statement/account days to months later,
 * never an at-checkout price reduction: 641/680 sampled promos' own `notes`
 * say so explicitly ("el reintegro se verá reflejado dentro de los 30 días",
 * "se acreditará en la caja de ahorro común"), and the remaining ones (pure
 * "N cuotas sin interés" promos with no `discount_percentage` at all, or a
 * handful whose `notes` just omit the word while describing the identical
 * per-`resumen` crediting mechanic) show zero counter-evidence of an
 * immediate discount anywhere in the catalog. So `discount_percentage` is
 * always mapped to `cashbackPercentage`, never `discountPercentage` — the
 * same reintegro-vs-descuento distinction already used for Galicia/ICBC/
 * Prex/Mercado Pago/Ualá elsewhere in this project.
 *
 * No structured validity-date field exists anywhere in the payload (only
 * `creation_date`, the record's own creation timestamp, and a `deleted_date`
 * that's always null for these already-"active"-filtered results) — the
 * closest thing is free-text mentions buried in `notes`, but in wildly
 * inconsistent shapes even within the same catalog: explicit ranges
 * ("Vigente...desde el 02/07/26 hasta el 24/09/26"), recurring-weekday text
 * with no end at all ("Vigente los días lunes de 2026"), specific date lists
 * ("los sábados 25 de julio, 29 de agosto y 26 de septiembre"), or just
 * "vigencia permanente". Confirmed live: a single regex anchored on the most
 * common phrasing only matched 3 of 680 sampled promos — far too unreliable
 * to parse without fabricating wrong dates for the rest, so `startDate`/
 * `endDate` stay null, an accepted limitation (same spirit as Prex's
 * scraper not risking a regex-based date range from free text).
 *
 * Categories (`heading`): 18 canonical rubros confirmed live, Spanish Title
 * Case with accents preserved (e.g. "Hogar y Decoración", "Indumentaria y
 * Accesorios", "Salud, Belleza y Deporte") — consistent capitalization, no
 * case-variant duplicates observed within this catalog. Some do collide with
 * an existing canonical category under a different name not already covered
 * by `config/category_aliases.php` (e.g. this catalog's "Automóviles y
 * Motos" vs. the existing canonical "Autos y motos") — left for a future,
 * separately-verified alias entry rather than guessed here.
 *
 * No minimum-purchase field exists on the payload at all (unlike
 * `discount_max`, which does and maps to `reimbursementCap`) — `minimumPurchase`
 * stays null, an accepted limitation.
 */
class CredicoopScraper implements WalletScraperInterface
{
    use MakesHttpRequests;

    private const string LISTING_URL = 'https://www.beneficios.bancocredicoop.coop/coop/beneficios/?p=search';

    private const string API_URL = 'https://www.beneficios.bancocredicoop.coop/coop/beneficios/xapi_get_coop_active_benefits_general.php';

    private const string DETAIL_URL_BASE = 'https://www.beneficios.bancocredicoop.coop/coop/beneficios/?p=benefit';

    private const string IMAGE_BASE_URL = 'https://www.beneficios.bancocredicoop.coop/coop/beneficios/images/logos200/';

    private const int PAGE_SIZE = 100;

    /**
     * Safety cap on listing pages so a pagination bug can't turn this into
     * an unbounded loop. The catalog observed live is 4580 promotions / 100
     * per page ≈ 46 pages.
     */
    private const int MAX_LISTING_PAGES = 100;

    /**
     * `discount_on_*`/`card_*` tri-state flags — confirmed live via the
     * site's own `get_days_of_discounts_array()`: `-1` means "applies",
     * `0` means "doesn't". Not a typo/bug in the source payload.
     */
    private const int FLAG_TRUE = -1;

    /**
     * @var array<string, string>
     */
    private const array DAY_FIELDS = [
        'discount_on_monday' => 'Lunes',
        'discount_on_tuesday' => 'Martes',
        'discount_on_wednesday' => 'Miércoles',
        'discount_on_thursday' => 'Jueves',
        'discount_on_friday' => 'Viernes',
        'discount_on_saturday' => 'Sábado',
        'discount_on_sunday' => 'Domingo',
    ];

    /**
     * @var array<string, string>
     */
    private const array CARD_FIELD_LABELS = [
        'card_cabal_credit' => 'Cabal Crédito',
        'card_cabal_debit' => 'Cabal Débito',
        'card_coop_visa' => 'Visa',
        'card_coop_mastercard' => 'Mastercard',
    ];

    public function walletSlug(): string
    {
        return 'credicoop';
    }

    public function scrape(): iterable
    {
        $session = $this->bootstrapSession();

        foreach ($this->fetchListing($session) as $item) {
            $dto = $this->buildDto($session, $item);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * Plain GET of the listing page to obtain a fresh `PHPSESSID` (from its
     * `Set-Cookie` response header) and the `csrf_token` embedded in its own
     * markup — both required by every `apiCall()` below. See the class
     * docblock: this is a real application-level CSRF/session check, not a
     * WAF, but without it every API call just returns
     * `{"msg":"CSRF Token Error"}`. Deliberately not best-effort — nothing
     * else in this scraper can proceed without it, the same way every other
     * wallet's own stage 1 listing failure isn't caught either.
     *
     * @return array{sessionCookie: string, csrfToken: string}
     */
    private function bootstrapSession(): array
    {
        $response = $this->http()->get(self::LISTING_URL)->throw();

        $sessionCookie = preg_match('/PHPSESSID=([^;]+)/', $response->header('Set-Cookie'), $cookieMatch) === 1
            ? $cookieMatch[1]
            : null;

        $csrfToken = preg_match('/id="header_csrf_token"[^>]*value="([^"]*)"/', $response->body(), $tokenMatch) === 1
            ? $tokenMatch[1]
            : null;

        if ($sessionCookie === null || $csrfToken === null || $csrfToken === '') {
            throw new RuntimeException('Credicoop: could not bootstrap a session (missing PHPSESSID cookie or csrf_token in the listing page).');
        }

        return ['sessionCookie' => $sessionCookie, 'csrfToken' => $csrfToken];
    }

    /**
     * @param  array{sessionCookie: string, csrfToken: string}  $session
     * @return iterable<int, array<string, mixed>>
     */
    private function fetchListing(array $session): iterable
    {
        $offset = 0;
        $page = 0;

        do {
            $json = $this->apiCall($session, ['p' => 'search', 'offset' => $offset, 'limit' => self::PAGE_SIZE]);
            $benefits = is_array($json['benefits'] ?? null) ? $json['benefits'] : [];

            foreach ($benefits as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $offset += self::PAGE_SIZE;
            $page++;
        } while (count($benefits) > 0 && $page < self::MAX_LISTING_PAGES);
    }

    /**
     * Stage 2 enrichment for a single promo. Never throws — any failure
     * (network error, unexpected shape) simply leaves the promo without
     * locations, mirroring every other wallet's own best-effort detail call
     * in this project.
     *
     * @param  array{sessionCookie: string, csrfToken: string}  $session
     * @return PromotionLocationDTO[]
     */
    private function fetchLocations(array $session, int $shopId, string $benefitId): array
    {
        try {
            $json = $this->apiCall($session, [
                'shop_id' => $shopId,
                'benefit_id' => $benefitId,
                'get_retails' => true,
            ]);
        } catch (Throwable) {
            return [];
        }

        $retails = is_array($json['benefits'][0]['retails'] ?? null) ? $json['benefits'][0]['retails'] : [];
        $locations = [];

        foreach ($retails as $retail) {
            if (! is_array($retail) || (int) ($retail['is_virtual_retail'] ?? 0) === self::FLAG_TRUE) {
                // See the class docblock: a virtual/online placeholder row,
                // not a real address — the site's own detail page skips
                // these too ("Ignoro las virtuales").
                continue;
            }

            $location = $this->toLocationDto($retail);

            if ($location !== null) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * @param  array<string, mixed>  $retail
     */
    private function toLocationDto(array $retail): ?PromotionLocationDTO
    {
        $address = $this->stringOrNull($retail['address_street'] ?? null);
        $city = $this->stringOrNull($retail['address_city'] ?? null);

        if ($address === null && $city === null) {
            return null;
        }

        return new PromotionLocationDTO(
            scope: 'store',
            province: $this->stringOrNull($retail['address_state'] ?? null),
            city: $city,
            address: $address,
            latitude: is_numeric($retail['lat'] ?? null) ? (float) $retail['lat'] : null,
            longitude: is_numeric($retail['lng'] ?? null) ? (float) $retail['lng'] : null,
        );
    }

    /**
     * The one JSON endpoint both the listing and the per-promo retails
     * lookup use — see the class docblock for the shared request/response
     * shape. Throws on both a network-level failure and the confirmed-live
     * "session expired" shape (`{"msg":"..."}` with no `benefits` key)
     * instead of silently reading the latter as "zero results", so a
     * mid-scrape session expiry surfaces as a failure rather than quietly
     * truncating the catalog.
     *
     * @param  array{sessionCookie: string, csrfToken: string}  $session
     * @param  array<string, mixed>  $search
     * @return array<string, mixed>
     */
    private function apiCall(array $session, array $search): array
    {
        $response = $this->http()
            ->withHeaders(['Cookie' => 'PHPSESSID='.$session['sessionCookie']])
            ->post(self::API_URL, [
                'data' => [
                    'search' => $search,
                    'csrf_token' => $session['csrfToken'],
                ],
            ])
            ->throw()
            ->json();

        $json = is_array($response) ? $response : [];

        if (! array_key_exists('benefits', $json) && isset($json['msg'])) {
            throw new RuntimeException('Credicoop API error: '.$json['msg']);
        }

        return $json;
    }

    /**
     * @param  array{sessionCookie: string, csrfToken: string}  $session
     * @param  array<string, mixed>  $item
     */
    private function buildDto(array $session, array $item): ?PromotionDTO
    {
        $merchantName = $this->stringOrNull($item['shop_name'] ?? null);
        $id = $item['id'] ?? null;

        if ($merchantName === null || (! is_int($id) && ! is_string($id))) {
            return null;
        }

        $id = (string) $id;
        $shopId = is_numeric($item['shop_id'] ?? null) ? (int) $item['shop_id'] : null;
        $title = $this->stringOrNull($item['display_name'] ?? null)
            ?? $this->stringOrNull($item['name'] ?? null)
            ?? $merchantName;

        [$discountPercentage, $cashbackPercentage] = $this->resolveDiscountAndCashback($item);

        $retailsCount = is_numeric($item['retails_count'] ?? null) ? (int) $item['retails_count'] : 0;
        $locations = $retailsCount > 0 && $shopId !== null
            ? $this->fetchLocations($session, $shopId, $id)
            : [];

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $title,
            merchantIconUrl: $this->resolveIconUrl($item),
            category: $this->stringOrNull($item['heading'] ?? null),
            description: $this->stringOrNull($item['description'] ?? null),
            discountPercentage: $discountPercentage,
            cashbackPercentage: $cashbackPercentage,
            installments: is_numeric($item['installments'] ?? null) ? (int) $item['installments'] : null,
            reimbursementCap: $this->positiveFloatOrNull($item['discount_max'] ?? null),
            validDays: $this->resolveValidDays($item),
            terms: $this->stringOrNull($item['notes'] ?? null),
            url: self::DETAIL_URL_BASE.'&benefit_id='.$id.'&shop_id='.($shopId ?? '').'&back=search',
            externalId: $id,
            paymentMethods: $this->resolvePaymentMethods($item),
            locations: $locations,
            rawPayload: $item,
        );
    }

    /**
     * See the class docblock: every promo in this catalog is a reintegro
     * (reimbursement credited later), never an at-checkout discount — so
     * `discount_percentage`, when present, always maps to
     * `cashbackPercentage`.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: ?float, 1: ?float}
     */
    private function resolveDiscountAndCashback(array $item): array
    {
        $value = $this->positiveFloatOrNull($item['discount_percentage'] ?? null);

        return [null, $value];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return string[]
     */
    private function resolveValidDays(array $item): array
    {
        $days = [];

        foreach (self::DAY_FIELDS as $field => $label) {
            if ((int) ($item[$field] ?? 0) === self::FLAG_TRUE) {
                $days[] = $label;
            }
        }

        return count($days) === 7 ? ['Todos los días'] : $days;
    }

    /**
     * `card_coop_only_modo`/`card_coop_modo` are mutually exclusive in
     * practice (confirmed live across every combination observed: never
     * both `-1` at once) — matching the site's own badge, which shows either
     * "Acepta MODO" or "Exclusivo MODO", never both.
     *
     * @param  array<string, mixed>  $item
     * @return string[]
     */
    private function resolvePaymentMethods(array $item): array
    {
        $methods = [];

        foreach (self::CARD_FIELD_LABELS as $field => $label) {
            if ((int) ($item[$field] ?? 0) === self::FLAG_TRUE) {
                $methods[] = $label;
            }
        }

        if ((int) ($item['card_coop_only_modo'] ?? 0) === self::FLAG_TRUE) {
            $methods[] = 'MODO (exclusivo)';
        } elseif ((int) ($item['card_coop_modo'] ?? 0) === self::FLAG_TRUE) {
            $methods[] = 'MODO';
        }

        return $methods;
    }

    /**
     * `logo_media_path` is already a full CDN URL when present; `image` is
     * a bare filename that lives under this site's own `images/logos200/`
     * directory instead (confirmed live via the listing page's own
     * `render_benefit()`).
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveIconUrl(array $item): ?string
    {
        $logoMediaPath = $this->stringOrNull($item['logo_media_path'] ?? null);

        if ($logoMediaPath !== null) {
            return $logoMediaPath;
        }

        $image = $this->stringOrNull($item['image'] ?? null);

        return $image !== null ? self::IMAGE_BASE_URL.rawurlencode($image).'.jpg' : null;
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
