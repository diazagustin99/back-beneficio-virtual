<?php

namespace App\Scrapers\Macro;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\DTOs\PromotionLocationDTO;
use App\Models\Merchant;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Throwable;

/**
 * macro.com.ar/beneficios is a jQuery/server-rendered site backed by a
 * genuinely public JSON API (`apipublic.macro.com.ar`) — its `apikey` ships
 * in the page's own JS bundle (`urlServicios.url_servicio_client_id`) and is
 * sent by every anonymous visitor, same threat model as Personal Pay/Naranja
 * X/MODO's public endpoints already scraped elsewhere in this project.
 *
 * There's no single "all promotions" endpoint: the listing is queried per
 * province (`/v1/card-benefits/provinces/{code}`, paginated via `?offset=N`
 * against `pagination.next-page`), and results overlap between neighboring
 * provinces (a Buenos Aires query already returns some CABA promos), so
 * stage 1 collects every province into a map keyed by the promo's own id
 * (the `city` field, e.g. `"47409TC2|47409"` — an unfortunate name for what
 * is actually the promotion identifier) to dedupe before doing any
 * per-promo work.
 *
 * Stage 2 enriches each unique id via `/v1/card-benefits/{id}` (confirmed
 * live by opening a promo's own "Ver más" detail page, which embeds this
 * same endpoint's URL in an inline `urlServicios` script). It adds real
 * validity dates, the full "Términos y condiciones" legal text, the actual
 * card names accepted (the listing only exposes a broad "TC"/"TD" code),
 * and — uniquely among every wallet scraped so far — a list of physical
 * store addresses (`details[].location`), which becomes `PromotionLocationDTO`
 * entries. Best-effort only: a failed detail fetch never aborts the scrape,
 * it just leaves that promo with its listing-only fields.
 */
class MacroScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string API_BASE = 'https://apipublic.macro.com.ar/v1/card-benefits/';

    private const string API_KEY = 'xoQHgmQk50pnZtGXLOxHowzjBEl4z0E7677knlgnD4iEL6sm';

    private const string LOGO_BASE_URL = 'https://d15j2h49piim29.cloudfront.net/';

    /**
     * Safety cap on pages per province so a pagination bug can't turn this
     * into an unbounded loop. The largest province observed live (Buenos
     * Aires) is 10 pages of 40.
     */
    private const int MAX_PAGES_PER_PROVINCE = 30;

    /**
     * The 24 ISO 3166-2:AR province codes, taken live from
     * `/v1/card-benefits/provinces` — there's no "nationwide" query, every
     * province has to be walked individually.
     *
     * @var list<string>
     */
    private const array PROVINCE_CODES = [
        'AR-C', 'AR-B', 'AR-K', 'AR-X', 'AR-W', 'AR-H', 'AR-U', 'AR-E',
        'AR-P', 'AR-Y', 'AR-L', 'AR-F', 'AR-M', 'AR-N', 'AR-Q', 'AR-R',
        'AR-A', 'AR-J', 'AR-D', 'AR-S', 'AR-Z', 'AR-G', 'AR-V', 'AR-T',
    ];

    /**
     * @var array<string, string>
     */
    private const array DAY_KEYS = [
        'monday' => 'Lunes', 'tuesday' => 'Martes', 'wednesday' => 'Miércoles',
        'thursday' => 'Jueves', 'friday' => 'Viernes', 'saturday' => 'Sábado', 'sunday' => 'Domingo',
    ];

    public function walletSlug(): string
    {
        return 'macro';
    }

    public function scrape(): iterable
    {
        // Stage 1: walk every province to completion, deduping by id — this
        // array only holds raw listing entries, not DTOs.
        $items = [];

        foreach (self::PROVINCE_CODES as $provinceCode) {
            foreach ($this->fetchProvinceItems($provinceCode) as $item) {
                $id = $item['city'] ?? null;

                if (is_string($id) && $id !== '') {
                    $items[$id] = $item;
                }
            }
        }

        // Stage 2: only now, one promo at a time, enrich with its own
        // detail endpoint. Best-effort — see the class docblock.
        foreach ($items as $id => $listing) {
            $dto = $this->buildDto((string) $id, $listing, $this->fetchDetail((string) $id));

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function fetchProvinceItems(string $provinceCode): iterable
    {
        $page = 1;

        do {
            $response = $this->http()
                ->withHeaders(['apikey' => self::API_KEY, 'Accept' => 'application/json'])
                ->get(self::API_BASE.'provinces/'.$provinceCode, ['offset' => $page])
                ->throw()
                ->json();

            $promotions = is_array($response['promotions'] ?? null) ? $response['promotions'] : [];

            foreach ($promotions as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
            $hasNext = (bool) ($pagination['next-page'] ?? false);
            $page++;
        } while ($hasNext && $page <= self::MAX_PAGES_PER_PROVINCE);
    }

    /**
     * Stage 2 enrichment for a single promo. Never throws — any failure
     * (network error, unexpected shape) yields `null`, and the caller
     * simply keeps the listing-only fields.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDetail(string $id): ?array
    {
        try {
            $response = $this->http()
                ->withHeaders(['apikey' => self::API_KEY, 'Accept' => 'application/json'])
                ->get(self::API_BASE.rawurlencode($id))
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        $promotion = $response['promotions'][0] ?? null;

        return is_array($promotion) ? $promotion : null;
    }

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     */
    private function buildDto(string $id, array $listing, ?array $detail): ?PromotionDTO
    {
        $rawMerchant = $this->stringOrNull($detail['name'] ?? null) ?? $this->stringOrNull($listing['name'] ?? null);

        if ($rawMerchant === null) {
            return null;
        }

        // Macro's own feed embeds payment-method tags directly in the name
        // (e.g. "HAVANNA GOOGLE PAY APPLE PAY") — never part of the
        // merchant's actual identity, see Merchant::stripPaymentMethodTags().
        $merchant = Merchant::stripPaymentMethodTags($rawMerchant);

        $discount = $this->resolveDiscount($listing, $detail);
        $installments = $this->resolveInstallments($listing, $detail);
        $logo = $this->stringOrNull($detail['logo'] ?? null) ?? $this->stringOrNull($listing['logo'] ?? null);

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $this->composeTitle($discount, $installments, $merchant),
            merchantIconUrl: $logo !== null ? self::LOGO_BASE_URL.$logo : null,
            category: $this->stringOrNull($detail['sector'] ?? null) ?? $this->stringOrNull($listing['sector'] ?? null),
            description: $this->composeDescription($listing, $detail),
            discountPercentage: $discount,
            installments: $installments,
            validDays: $this->resolveValidDays($listing, $detail),
            startDate: $detail !== null ? $this->parseIsoDate($this->stringOrNull($detail['valid-date-from'] ?? null)) : null,
            endDate: $detail !== null ? $this->parseIsoDate($this->stringOrNull($detail['valid-date-to'] ?? null)) : null,
            terms: $detail !== null ? $this->stringOrNull($detail['terms-conditions'] ?? null) : null,
            externalId: $id,
            paymentMethods: $this->resolvePaymentMethods($detail),
            locations: $detail !== null ? $this->extractLocations($detail) : [],
            rawPayload: $detail ?? $listing,
        );
    }

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     */
    private function resolveDiscount(array $listing, ?array $detail): ?float
    {
        $fromDetail = $detail['discount']['maximum'] ?? null;

        if (is_numeric($fromDetail)) {
            return (float) $fromDetail;
        }

        $fromListing = $listing['discount'] ?? null;

        return is_numeric($fromListing) ? (float) $fromListing : null;
    }

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     */
    private function resolveInstallments(array $listing, ?array $detail): ?int
    {
        $fromDetail = $detail['payment']['maximum'] ?? null;

        if (is_numeric($fromDetail)) {
            return (int) $fromDetail;
        }

        $fromListing = $listing['payment']['maximum'] ?? null;

        return is_numeric($fromListing) ? (int) $fromListing : null;
    }

    /**
     * The listing only ever exposes a broad payment code ("TC"/"TD"), never
     * real card names — only the detail endpoint's `payment.method` is a
     * genuine comma-separated list of card products.
     *
     * @param  array<string, mixed>|null  $detail
     * @return string[]
     */
    private function resolvePaymentMethods(?array $detail): array
    {
        $method = $detail['payment']['method'] ?? null;

        if (! is_string($method) || $method === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $method)), fn ($m) => $m !== ''));
    }

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     * @return string[]
     */
    private function resolveValidDays(array $listing, ?array $detail): array
    {
        $text = $detail['day-week'] ?? null;

        if (is_string($text) && $text !== '') {
            return $this->parseDayText($text);
        }

        $map = $listing['days-week'] ?? null;

        return is_array($map) ? $this->daysFromBooleanMap($map) : [];
    }

    /**
     * Splits the source's own day text (e.g. "Jueves, Viernes y Sábados",
     * or "Todos los días" with nothing to split) without altering its
     * casing — `ListPromotionsAction` matches the literal string
     * "Todos los días", so it must survive untouched.
     *
     * @return string[]
     */
    private function parseDayText(string $text): array
    {
        $normalized = str_replace(' y ', ',', $text);

        return array_values(array_filter(array_map('trim', explode(',', $normalized)), fn ($day) => $day !== ''));
    }

    /**
     * @param  array<string, mixed>  $map
     * @return string[]
     */
    private function daysFromBooleanMap(array $map): array
    {
        $days = [];

        foreach (self::DAY_KEYS as $key => $label) {
            if (($map[$key] ?? false) === true) {
                $days[] = $label;
            }
        }

        return count($days) === 7 ? ['Todos los días'] : $days;
    }

    /**
     * The listing has no description-worthy field; the detail endpoint's
     * `segment` ("Selecta"/"General"/"Exclusive Banking") and `campaigns`
     * (e.g. "ONLINE") are the closest thing to it — "General" is the
     * default tier and not worth surfacing.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $detail
     */
    private function composeDescription(array $listing, ?array $detail): ?string
    {
        $segment = $this->stringOrNull($detail['segment'] ?? null) ?? $this->stringOrNull($listing['segment'] ?? null);
        $campaignName = $this->stringOrNull($detail['campaigns'][0]['name'] ?? null);

        $parts = array_filter([
            $segment !== null && strcasecmp($segment, 'General') !== 0 ? $segment : null,
            $campaignName,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Neither source exposes a natural headline string — the site itself
     * builds the card title client-side from discount/installments, so
     * this mirrors that instead of leaving `title` empty.
     */
    private function composeTitle(?float $discount, ?int $installments, string $merchant): string
    {
        $parts = [];

        if ($discount !== null && $discount > 0) {
            $parts[] = ((int) $discount).'% de ahorro';
        }

        if ($installments !== null && $installments > 1) {
            $parts[] = "hasta {$installments} cuotas sin interés";
        }

        return $parts !== [] ? implode(' y ', $parts) : $merchant;
    }

    /**
     * `details[].location` is the one field only the detail endpoint
     * exposes — physical store addresses, unused by every other scraper in
     * this project so far. `latitude`/`longitude` are always `0` in this
     * feed (never real coordinates), so they're left null rather than
     * persisted as null-island.
     *
     * @param  array<string, mixed>  $detail
     * @return PromotionLocationDTO[]
     */
    private function extractLocations(array $detail): array
    {
        $entries = $detail['details'] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        $locations = [];

        foreach ($entries as $entry) {
            $location = is_array($entry) ? ($entry['location'] ?? null) : null;

            if (! is_array($location)) {
                continue;
            }

            $street = $this->stringOrNull($location['street'] ?? null);
            $city = $this->stringOrNull($location['city'] ?? null);

            if ($street === null && $city === null) {
                continue;
            }

            $locations[] = new PromotionLocationDTO(
                scope: 'store',
                province: $this->stringOrNull($location['province-code'] ?? null),
                city: $city,
                address: $street,
                storeName: $this->stringOrNull($location['name'] ?? null),
            );
        }

        return $locations;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
