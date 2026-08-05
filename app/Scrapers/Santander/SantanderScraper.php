<?php

namespace App\Scrapers\Santander;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\DTOs\PromotionLocationDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * santander.com.ar/personas/beneficios#/results is a Next.js (Pages Router)
 * marketing site whose page for `/personas/beneficios` is CMS-driven (a list
 * of typed "componentes" resolved client-side) — one of those components,
 * `ComponentComponentesBeneficiosMf`, is an empty mount point: the actual
 * catalog is a separate ~2MB Create React App bundle ("micro-frontend"),
 * whose own URL the page fetches at runtime from its own
 * `GET /api/microfrontBeneficios` route (`{"url":
 * "https://www.santander.com.ar/microfront-benefits"}`, confirmed live) and
 * injects as a `<script>` tag. That bundle's build-time-inlined
 * `REACT_APP_API_ENDPOINT` env var (`"/bff-benefits"`, confirmed live inside
 * the downloaded bundle) is the real, genuinely public, unauthenticated JSON
 * API this scraper talks to — reverse-proxied same-origin through
 * www.santander.com.ar, no API key/cookies/Authorization header sent by the
 * real widget (confirmed live).
 *
 * IMPORTANT — how that bundle was actually obtained, because it drives a
 * deliberate choice below: a first attempt to simply GET
 * `www.santander.com.ar/personas/beneficios` with this project's usual
 * realistic-Chrome User-Agent (the shared `MakesHttpRequests` trait) hung
 * forever with zero bytes back — on EVERY path on that host, including
 * static JS assets, not just the SPA route. Confirmed live, repeatedly: this
 * is Akamai Bot Manager (Server header `AkamaiGHost`) tarpitting the TCP
 * connection rather than returning any HTTP response. Isolated the actual
 * trigger by trial: a request with NO User-Agent, or a Firefox User-Agent,
 * gets the exact same silent hang; a request with an honest, non-browser
 * User-Agent (e.g. `GuzzleHttp/7`, curl's own default, or simply omitting
 * the header override) gets an instant `200`. In other words Akamai here
 * isn't blocking bots — it's blocking clients that CLAIM to be a real
 * desktop browser without the matching TLS/HTTP2 fingerprint, and lets
 * clients that don't make that claim through. Since PHP's HTTP client is the
 * same underlying cURL/libcurl stack as the `curl` binary used to confirm
 * this, this is expected to reproduce identically in production. This is the
 * one wallet in this project where impersonating a browser is the thing that
 * gets a scraper blocked, so — deliberately, see `santanderHttp()` below —
 * this scraper overrides the shared trait's realistic-Chrome User-Agent
 * back to an honest one instead of relying on it.
 *
 * Stage 1 (listing) walks `/bff-benefits/brands` (paginated via
 * `page`/`limit`), sending NO filter params at all. This matters: the task's
 * own reference URL (`#/results?weekday-id=-1`) suggests `-1` means "no día
 * filter" (its UI label is literally "Todos los días"), but confirmed live
 * this is a REAL filter, not a wildcard — `days=-1` returns only the 140
 * (of 620) publications whose own `fullWeek` flag is true, not the full
 * catalog. Omitting every filter param entirely is what actually returns the
 * complete, unfiltered 620-brand catalog (confirmed live), so that's what
 * this scraper does.
 *
 * Each brand from stage 1 only carries a name/logo/colour and a bare summary
 * string (`benefitDescription`, e.g. `"30%"`) — no discount mechanics, dates,
 * or legal text. Stage 2, `/bff-benefits/brands/{brandId}`, supplies those:
 * confirmed live to return a list of that brand's own "programs" — almost
 * always exactly one, but occasionally more than one distinct promotion for
 * the same brand (e.g. a weekday-specific deal alongside a Sorpresa Santander
 * one). Confirmed live: the very same program id can also be shared by SEVERAL
 * different brands at once (e.g. ids 7585/7586 returned by both brand 184
 * "Farmacity" and brand 495 "The Food Market") — this is a genuine
 * multi-brand consortium promotion, not a data bug, so `externalId` combines
 * `{brandId}-{programId}` rather than the bare program id. Stage 2 is treated
 * like every other wallet's "detail" call in this project: best-effort, and
 * a failure doesn't drop the brand — it falls back to a minimal DTO built
 * from stage 1's own `benefitDescription` string (regex-extracted
 * percentage), mirroring Galicia's regex-fallback-from-listing pattern.
 *
 * Stage 3 (further enrichment, also best-effort) is
 * `/bff-benefits/publications/{programId}?brandId={brandId}` — confirmed
 * live to add two things stage 2 never has: the real accepted card list
 * (`cards[]`, e.g. "VISA" débito/crédito — stage 2's own `paymentMethod`
 * field is always null) and, for brands with physical stores, that specific
 * brand's own address list (`brands[].establishments[]`, matched by
 * `brand.id` — confirmed live the array can list OTHER participating brands
 * of the same shared promotion too, up to 364 branches for a single
 * nationwide chain). A failure here only leaves paymentMethods/locations
 * empty, exactly like Galicia/Macro's own detail-call best-effort handling.
 *
 * `benefitType.description` is always the literal string "Ahorro" whether
 * the mechanic is an immediate at-checkout discount or a reintegro credited
 * days later — confirmed live on both a same-day-discount promo and a
 * 30-days-later-reintegro one. Distinguished the same way as Prex's scraper:
 * the free-text `legal`/`additionalText` mentioning "reintegro" flags
 * `cashbackPercentage` instead of `discountPercentage`.
 *
 * Categories: confirmed live via `/bff-benefits/categories?type=STA` (18
 * genuine retail codes, Spanish Title Case with accents preserved —
 * "Gastronomía", "Espectáculos", "Librerías", ...). A promo's own
 * `categories[]` array mixes these in with promotional tag-like codes from a
 * separate `type=EXC` list (Select/Modo/Sorpresa) plus a couple of ad-hoc
 * badges in neither list (`DES`="Destacado", `DAY`="Everyday", the latter in
 * English) — `category` picks the first entry matching the confirmed STA
 * code set, ignoring the tag-like ones, instead of surfacing whichever
 * happens to be first.
 */
class SantanderScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string API_BASE = 'https://www.santander.com.ar/bff-benefits/';

    private const string BRAND_URL_BASE = 'https://www.santander.com.ar/personas/beneficios#/brand';

    /**
     * See the class docblock: an honest, non-browser-claiming User-Agent —
     * this is what actually gets a `200` from www.santander.com.ar, unlike
     * the shared trait's realistic-Chrome one.
     */
    private const string HONEST_USER_AGENT = 'GuzzleHttp/7';

    private const int PAGE_SIZE = 100;

    /**
     * Safety cap on listing pages. The catalog observed live is 620 brands /
     * 100 per page = 7 pages.
     */
    private const int MAX_LISTING_PAGES = 20;

    /**
     * The genuine retail category codes, confirmed live via
     * `/bff-benefits/categories?type=STA` — see the class docblock for why
     * this set exists (a promo's own `categories[]` mixes these with
     * promotional tags).
     *
     * @var list<string>
     */
    private const array RETAIL_CATEGORY_CODES = [
        'PET', 'CPE', 'DEP', 'FAR', 'GAS', 'HOG', 'VIA', 'VAR', 'IND', 'EDU',
        'ESP', 'JUG', 'LIB', 'SUP', 'AUT', 'DIN', 'PER', 'PELU',
    ];

    /**
     * @var array<string, string>
     */
    private const array DAY_FIELDS = [
        'monday' => 'Lunes', 'tuesday' => 'Martes', 'wednesday' => 'Miércoles', 'thursday' => 'Jueves',
        'friday' => 'Viernes', 'saturday' => 'Sábado', 'sunday' => 'Domingo',
    ];

    /**
     * `cards[].descriptionType` values confirmed live — used to turn a bare
     * "VISA" into "VISA Débito"/"VISA Crédito".
     *
     * @var array<string, string>
     */
    private const array CARD_TYPE_LABELS = [
        'DEBITO' => 'Débito',
        'CREDITO' => 'Crédito',
    ];

    public function walletSlug(): string
    {
        return 'santander';
    }

    public function scrape(): iterable
    {
        foreach ($this->fetchBrandListing() as $brandListing) {
            $brandId = $brandListing['id'] ?? null;

            if (! is_int($brandId)) {
                continue;
            }

            $programs = $this->fetchPrograms($brandId);

            if ($programs === null) {
                // Stage 2 failed entirely — best-effort fallback to
                // stage 1's own bare summary, same spirit as Galicia's
                // regex-fallback-from-listing when its detail call 404s.
                $dto = $this->buildFallbackDto($brandId, $brandListing);

                if ($dto !== null) {
                    yield $dto;
                }

                continue;
            }

            foreach ($programs as $program) {
                $programId = $program['id'] ?? null;

                if (! is_int($programId)) {
                    continue;
                }

                $detail = $this->fetchPublicationDetail($brandId, $programId);
                $dto = $this->buildDto($brandId, $programId, $brandListing, $program, $detail);

                if ($dto !== null) {
                    yield $dto;
                }
            }
        }
    }

    /**
     * See the class docblock: deliberately overrides the shared trait's
     * realistic-Chrome User-Agent with an honest, non-browser one — that's
     * what actually gets a response from www.santander.com.ar.
     */
    private function santanderHttp(): PendingRequest
    {
        return $this->http()->withUserAgent(self::HONEST_USER_AGENT);
    }

    /**
     * Stage 1. Deliberately sends no category/day/payment/etc. filter — see
     * the class docblock for why that (not `days=-1`) is what returns the
     * complete, unfiltered catalog.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function fetchBrandListing(): iterable
    {
        $page = 1;

        do {
            $response = $this->santanderHttp()
                ->get(self::API_BASE.'brands', [
                    'page' => $page,
                    'limit' => self::PAGE_SIZE,
                ])
                ->throw()
                ->json();

            $items = is_array($response['items'] ?? null) ? $response['items'] : [];

            foreach ($items as $item) {
                if (is_array($item) && ($item['active'] ?? true) !== false) {
                    yield $item;
                }
            }

            $page++;
        } while (count($items) > 0 && $page <= self::MAX_LISTING_PAGES);
    }

    /**
     * Stage 2 for one brand. Never throws — returns `null` (not `[]`) on
     * failure, distinct from a legitimately empty catalogue entry, so the
     * caller can tell "fetch failed, fall back" apart from "no programs".
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchPrograms(int $brandId): ?array
    {
        try {
            $response = $this->santanderHttp()
                ->get(self::API_BASE.'brands/'.$brandId)
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        $items = is_array($response['items'] ?? null) ? $response['items'] : [];

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * Stage 3 for one program. Never throws — any failure simply leaves the
     * promo without a real card list or physical locations.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPublicationDetail(int $brandId, int $programId): ?array
    {
        try {
            $response = $this->santanderHttp()
                ->get(self::API_BASE.'publications/'.$programId, ['brandId' => $brandId])
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        return is_array($response) ? $response : null;
    }

    /**
     * @param  array<string, mixed>  $brandListing
     * @param  array<string, mixed>  $program
     * @param  array<string, mixed>|null  $detail
     */
    private function buildDto(int $brandId, int $programId, array $brandListing, array $program, ?array $detail): ?PromotionDTO
    {
        $merchantName = $this->resolveMerchantName($brandId, $program) ?? $this->stringOrNull($brandListing['name'] ?? null);

        if ($merchantName === null) {
            return null;
        }

        $texts = is_array($program['texts'] ?? null) ? $program['texts'] : [];
        $title = $this->stringOrNull($texts['title'] ?? null) ?? $merchantName;

        [$discount, $cashback] = $this->resolveDiscountAndCashback($program);

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $title,
            merchantIconUrl: $this->stringOrNull($brandListing['desktopImage'] ?? null) ?? $this->stringOrNull($brandListing['mobileImage'] ?? null),
            category: $this->resolveCategory($program),
            description: $this->stringOrNull($texts['description'] ?? null),
            discountPercentage: $discount,
            cashbackPercentage: $cashback,
            installments: $this->resolveInstallments($program),
            reimbursementCap: $this->positiveFloatOrNull($program['topAmount'] ?? null),
            validDays: $this->resolveValidDays($program),
            startDate: $this->parseIsoDate($this->stringOrNull($program['startDatePublication'] ?? null)),
            endDate: $this->parseIsoDate($this->stringOrNull($program['endDatePublication'] ?? null)),
            terms: $this->resolveTerms($program),
            url: self::BRAND_URL_BASE.'?brandId='.$brandId.'&programId='.$programId,
            externalId: $brandId.'-'.$programId,
            paymentMethods: $this->resolvePaymentMethods($detail),
            locations: $this->resolveLocations($detail, $brandId),
            rawPayload: $detail ?? $program,
        );
    }

    /**
     * Best-effort fallback when stage 2 fails outright for a brand — builds
     * a minimal DTO from stage 1's own bare summary string alone, mirroring
     * Galicia's regex-fallback-from-listing pattern.
     *
     * @param  array<string, mixed>  $brandListing
     */
    private function buildFallbackDto(int $brandId, array $brandListing): ?PromotionDTO
    {
        $merchantName = $this->stringOrNull($brandListing['name'] ?? null);

        if ($merchantName === null) {
            return null;
        }

        $summary = $this->stringOrNull($brandListing['benefitDescription'] ?? null);
        $discount = $summary !== null ? $this->extractPercentage($summary) : null;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $summary ?? $merchantName,
            merchantIconUrl: $this->stringOrNull($brandListing['desktopImage'] ?? null) ?? $this->stringOrNull($brandListing['mobileImage'] ?? null),
            discountPercentage: $discount,
            url: self::BRAND_URL_BASE.'?brandId='.$brandId,
            externalId: (string) $brandId,
            rawPayload: $brandListing,
        );
    }

    /**
     * The program's own `brands[]` names the merchant directly — matched by
     * id rather than assumed to be index 0, since a shared multi-brand
     * promotion (see class docblock) lists every participating brand there.
     *
     * @param  array<string, mixed>  $program
     */
    private function resolveMerchantName(int $brandId, array $program): ?string
    {
        $brands = is_array($program['brands'] ?? null) ? $program['brands'] : [];

        foreach ($brands as $brand) {
            if (is_array($brand) && (int) ($brand['id'] ?? -1) === $brandId) {
                return $this->stringOrNull($brand['name'] ?? null);
            }
        }

        return $this->stringOrNull($brands[0]['name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $program
     */
    private function resolveCategory(array $program): ?string
    {
        $categories = is_array($program['categories'] ?? null) ? $program['categories'] : [];

        foreach ($categories as $category) {
            if (is_array($category) && in_array($category['code'] ?? null, self::RETAIL_CATEGORY_CODES, true)) {
                return $this->stringOrNull($category['description'] ?? null);
            }
        }

        return $this->stringOrNull($categories[0]['description'] ?? null);
    }

    /**
     * See the class docblock: `benefitType.description` ("Ahorro") doesn't
     * distinguish an at-checkout discount from a days-later reintegro — the
     * free text does, same heuristic as PrexScraper.
     *
     * @param  array<string, mixed>  $program
     * @return array{0: ?float, 1: ?float}
     */
    private function resolveDiscountAndCashback(array $program): array
    {
        $value = $this->positiveFloatOrNull($program['customerDiscount'] ?? null);

        if ($value === null) {
            return [null, null];
        }

        $text = ($this->stringOrNull($program['legal'] ?? null) ?? '').' '.($this->stringOrNull($program['additionalText'] ?? null) ?? '');
        $isCashback = preg_match('/reintegro/iu', $text) === 1;

        return $isCashback ? [null, $value] : [$value, null];
    }

    /**
     * `finalQuote` is only a genuine "hasta N cuotas sin interés" count when
     * `interestFreeFees` is true — confirmed live: promos with no
     * interest-free financing at all still carry `initialQuote`/`finalQuote`,
     * just both `0`.
     *
     * @param  array<string, mixed>  $program
     */
    private function resolveInstallments(array $program): ?int
    {
        if (($program['interestFreeFees'] ?? false) !== true) {
            return null;
        }

        $finalQuote = $program['finalQuote'] ?? null;

        return is_numeric($finalQuote) && (int) $finalQuote > 0 ? (int) $finalQuote : null;
    }

    /**
     * @param  array<string, mixed>  $program
     * @return string[]
     */
    private function resolveValidDays(array $program): array
    {
        if (($program['fullWeek'] ?? false) === true) {
            return ['Todos los días'];
        }

        $days = [];

        foreach (self::DAY_FIELDS as $field => $label) {
            if (($program[$field] ?? false) === true) {
                $days[] = $label;
            }
        }

        return count($days) === 7 ? ['Todos los días'] : $days;
    }

    /**
     * `additionalText` (a short highlight, e.g. which contactless wallets
     * qualify) and `legal` (the full "Términos y Condiciones") are both rich
     * HTML — combined here since `additionalText` often carries the only
     * mention of which specific payment channel/app is required.
     *
     * @param  array<string, mixed>  $program
     */
    private function resolveTerms(array $program): ?string
    {
        $parts = array_filter([
            $this->stringOrNull($program['additionalText'] ?? null),
            $this->stringOrNull($program['legal'] ?? null),
        ]);

        $texts = array_filter(array_map(fn ($html) => $this->htmlToPlainText($html), $parts));

        return $texts === [] ? null : implode("\n\n", $texts);
    }

    /**
     * Stage 3's `cards[]` is the only place a real card brand/type is
     * exposed — stage 2's own `paymentMethod` field is always null
     * (confirmed live).
     *
     * @param  array<string, mixed>|null  $detail
     * @return string[]
     */
    private function resolvePaymentMethods(?array $detail): array
    {
        $cards = is_array($detail['cards'] ?? null) ? $detail['cards'] : [];
        $names = [];

        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }

            $description = $this->stringOrNull($card['description'] ?? null);

            if ($description === null) {
                continue;
            }

            $typeLabel = self::CARD_TYPE_LABELS[$card['descriptionType'] ?? ''] ?? null;
            $names[] = $typeLabel !== null ? "{$description} {$typeLabel}" : $description;
        }

        return array_values(array_unique($names));
    }

    /**
     * Stage 3's `brands[]` lists every brand participating in a shared
     * promotion (see class docblock), each with its own `establishments[]` —
     * matched by id rather than assumed to be the only entry.
     *
     * @param  array<string, mixed>|null  $detail
     * @return PromotionLocationDTO[]
     */
    private function resolveLocations(?array $detail, int $brandId): array
    {
        $brands = is_array($detail['brands'] ?? null) ? $detail['brands'] : [];
        $entry = null;

        foreach ($brands as $candidate) {
            if (is_array($candidate) && (int) ($candidate['brand']['id'] ?? -1) === $brandId) {
                $entry = $candidate;
                break;
            }
        }

        $establishments = is_array($entry['establishments'] ?? null) ? $entry['establishments'] : [];
        $locations = [];

        foreach ($establishments as $establishment) {
            if (! is_array($establishment)) {
                continue;
            }

            $location = $this->toLocationDto($establishment);

            if ($location !== null) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * @param  array<string, mixed>  $establishment
     */
    private function toLocationDto(array $establishment): ?PromotionLocationDTO
    {
        $address = $this->stringOrNull($establishment['address'] ?? null);
        $city = $this->stringOrNull($establishment['city'] ?? null);

        if ($address === null && $city === null) {
            return null;
        }

        return new PromotionLocationDTO(
            scope: 'store',
            province: $this->stringOrNull($establishment['province'] ?? null),
            city: $city,
            address: $address,
            storeName: $this->stringOrNull($establishment['fantasyName'] ?? null),
            latitude: is_numeric($establishment['latitude'] ?? null) ? (float) $establishment['latitude'] : null,
            longitude: is_numeric($establishment['longitude'] ?? null) ? (float) $establishment['longitude'] : null,
        );
    }

    private function htmlToPlainText(string $html): string
    {
        $html = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h2>', '</h3>'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;
        $lines = array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== '');

        return trim(implode("\n", $lines));
    }

    private function extractPercentage(string $text): ?float
    {
        return preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $text, $matches) === 1
            ? (float) str_replace(',', '.', $matches[1])
            : null;
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
