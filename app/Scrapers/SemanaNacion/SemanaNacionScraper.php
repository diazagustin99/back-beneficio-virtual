<?php

namespace App\Scrapers\SemanaNacion;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Throwable;

/**
 * semananacion.com.ar/buscador (Banco Nación's "Semana Nación" promotions
 * microsite) is a Next.js SPA whose SSR shell embeds an empty catalog — the
 * ~650-brand catalog and its promotions are fetched entirely client-side from
 * a shared whitelabel backend (`digiventures.la`, evidently reused across
 * several Argentine bank promo microsites via the `clientId` below).
 *
 * Reverse-engineered via the site's own network calls (no documented API):
 *  - `GET /api/categories?clientId=` — category id → label map.
 *  - `GET /api/brands?clientId=` — the full brand catalog with ids (needed
 *    because `brands/with-promotions` requires an explicit `brandIds` list;
 *    there is no "all" shortcut).
 *  - `POST /api/brands/with-promotions` with every brand id, `checkValidity`
 *    and a high `limit` — returns every currently valid promotion, each
 *    covering one or more brands (a single promo can be a whole-category
 *    perk shared by dozens of brands, or a brand-specific one).
 *  - Each brand's own landing page (`brand.url`) is a Next.js page whose
 *    `__NEXT_DATA__` embeds `pageProps.data.termsAndConditions` (the full
 *    legal text) — fetched once per unique brand url. That same page, for
 *    most single-promotion brands (confirmed live: 5 of 6 sampled brand
 *    pages, the exception being brands whose page groups several promo
 *    variants together instead), also embeds an `offer` component under
 *    `pageProps.data.pages[0].components` with `extraData.activeDays`
 *    (2-letter weekday codes) and `extraData.paymentMethods` (e.g.
 *    `"visa-credit"`, `"mc-debit"`) — neither of which the listing endpoint
 *    exposes at all. Since it's the exact same request already made for
 *    `termsAndConditions`, this costs zero extra HTTP calls. When a brand's
 *    page doesn't have a matching `offer` (grouped/hub-style pages), these
 *    two fields are simply left empty, same as before this enrichment.
 */
class SemanaNacionScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string BASE_URL = 'https://backend.activx.production.digiventures.la';

    private const string REFERER = 'https://semananacion.com.ar/buscador';

    /**
     * The tenant id for the `bna-semananacion` whitelabel site. Fixed per
     * site — observed identically on every request the SPA itself makes.
     */
    private const string CLIENT_ID = '644ab05fa2138709cee22597';

    private const string SELECT_FOR_BRANDS = 'media.catalogImage campaign title url name type';

    private const string SELECT_FOR_PROMOS = 'images.catalogImage incentive name promotionTitle url startDate endDate brands';

    public function walletSlug(): string
    {
        return 'bna';
    }

    public function scrape(): iterable
    {
        $categories = $this->fetchCategoryMap();
        $brandIds = $this->fetchBrandIds();

        if ($brandIds === []) {
            return;
        }

        $pageCache = [];

        foreach ($this->fetchPromotions($brandIds) as $promotion) {
            if (! is_array($promotion)) {
                continue;
            }

            $brands = $promotion['brands'] ?? [];

            if (! is_array($brands)) {
                continue;
            }

            foreach ($brands as $brand) {
                if (! is_array($brand)) {
                    continue;
                }

                $dto = $this->toDto($promotion, $brand, $categories, $pageCache);

                if ($dto !== null) {
                    yield $dto;
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function fetchCategoryMap(): array
    {
        $categories = $this->http()
            ->withHeaders(['Referer' => self::REFERER])
            ->get(self::BASE_URL.'/api/categories', ['clientId' => self::CLIENT_ID])
            ->throw()
            ->json();

        if (! is_array($categories)) {
            return [];
        }

        $map = [];

        foreach ($categories as $category) {
            if (is_array($category) && is_string($category['_id'] ?? null) && is_string($category['label'] ?? null)) {
                $map[$category['_id']] = $category['label'];
            }
        }

        return $map;
    }

    /**
     * @return string[]
     */
    private function fetchBrandIds(): array
    {
        $brands = $this->http()
            ->withHeaders(['Referer' => self::REFERER])
            ->get(self::BASE_URL.'/api/brands', ['clientId' => self::CLIENT_ID])
            ->throw()
            ->json();

        if (! is_array($brands)) {
            return [];
        }

        $ids = [];

        foreach ($brands as $brand) {
            if (is_array($brand) && is_string($brand['_id'] ?? null)) {
                $ids[] = $brand['_id'];
            }
        }

        return $ids;
    }

    /**
     * @param  string[]  $brandIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchPromotions(array $brandIds): array
    {
        $response = $this->http()
            ->withHeaders(['Referer' => self::REFERER])
            ->post(self::BASE_URL.'/api/brands/with-promotions', [
                'clientId' => self::CLIENT_ID,
                'brandIds' => $brandIds,
                'selectForBrands' => self::SELECT_FOR_BRANDS,
                'selectForPromos' => self::SELECT_FOR_PROMOS,
                'limit' => 1000,
                'checkValidity' => true,
            ])
            ->throw()
            ->json();

        $items = is_array($response) ? ($response['promotionsData'] ?? []) : [];

        return is_array($items) ? $items : [];
    }

    /**
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $brand
     * @param  array<string, string>  $categories
     * @param  array<string, ?array<string, mixed>>  $pageCache
     */
    private function toDto(array $promotion, array $brand, array $categories, array &$pageCache): ?PromotionDTO
    {
        $merchantName = $brand['title'] ?? null;
        $title = $promotion['promotionTitle'] ?? null;

        if (! is_string($merchantName) || $merchantName === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $categoryId = $promotion['categories'][0]['_id'] ?? null;
        $categoryLabel = $promotion['categories'][0]['label'] ?? null;
        $category = is_string($categoryId) ? ($categories[$categoryId] ?? null) : null;
        $category ??= is_string($categoryLabel) ? $categoryLabel : null;

        $incentive = is_array($promotion['incentive'] ?? null) ? $promotion['incentive'] : [];
        $discount = is_array($incentive['discount'] ?? null) ? $incentive['discount'] : [];
        $value = is_numeric($discount['value'] ?? null) ? (float) $discount['value'] : null;
        $isCashback = is_array($discount['cashbackType'] ?? null) && $discount['cashbackType'] !== [];

        $installmentValues = $incentive['installment']['value'] ?? null;
        $installmentValues = is_array($installmentValues) ? array_filter($installmentValues, 'is_numeric') : [];

        $brandUrl = is_string($brand['url'] ?? null) && $brand['url'] !== '' ? $brand['url'] : null;
        $iconUrl = $brand['media']['catalogImage'] ?? $promotion['images']['catalogImage'] ?? null;
        $promotionId = is_string($promotion['_id'] ?? null) ? $promotion['_id'] : 'unknown';
        $brandId = is_string($brand['_id'] ?? null) ? $brand['_id'] : $merchantName;

        $pageProps = $brandUrl !== null ? $this->fetchLandingPage($brandUrl, $pageCache) : null;
        $offerDetail = $this->resolveOfferDetail($pageProps, $promotionId);

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $title,
            merchantIconUrl: is_string($iconUrl) && $iconUrl !== '' ? $iconUrl : null,
            category: $category,
            discountPercentage: ($value !== null && $value > 0 && ! $isCashback) ? $value : null,
            cashbackPercentage: ($value !== null && $value > 0 && $isCashback) ? $value : null,
            installments: $installmentValues !== [] ? (int) max($installmentValues) : null,
            reimbursementCap: $isCashback && is_numeric($discount['cashbackLimit'] ?? null) ? (float) $discount['cashbackLimit'] : null,
            validDays: $offerDetail['validDays'],
            startDate: $this->parseIsoDate(is_string($promotion['startDate'] ?? null) ? $promotion['startDate'] : null),
            endDate: $this->parseIsoDate(is_string($promotion['endDate'] ?? null) ? $promotion['endDate'] : null),
            terms: $this->resolveTerms($pageProps),
            url: $brandUrl,
            externalId: $promotionId.':'.$brandId,
            paymentMethods: $offerDetail['paymentMethods'],
            rawPayload: ['promotion' => $promotion, 'brand' => $brand],
        );
    }

    /**
     * Fetches and decodes a brand's landing page once per unique URL,
     * regardless of how many promotions/brands share it. Never throws — any
     * failure (network, missing `__NEXT_DATA__`, malformed JSON) caches and
     * returns `null`, and callers fall back to empty enrichment fields.
     *
     * @param  array<string, ?array<string, mixed>>  $pageCache
     * @return array<string, mixed>|null
     */
    private function fetchLandingPage(string $url, array &$pageCache): ?array
    {
        if (array_key_exists($url, $pageCache)) {
            return $pageCache[$url];
        }

        try {
            $html = $this->http()->get($url)->throw()->body();
        } catch (Throwable) {
            return $pageCache[$url] = null;
        }

        if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return $pageCache[$url] = null;
        }

        $data = json_decode($matches[1], true);
        $pageProps = is_array($data) ? ($data['props']['pageProps'] ?? null) : null;

        return $pageCache[$url] = is_array($pageProps) ? $pageProps : null;
    }

    /**
     * `data.termsAndConditions` is the common case, but confirmed live
     * (Shell's own landing page) some brands don't have it set at all —
     * their only copy of the legal text lives inside a `multiaccordionv2`
     * component further down the same page, in the step whose subtitle
     * names it ("Ver bases y condiciones"). That accordion type is also
     * reused for unrelated widgets on some pages (a store locator, a
     * "buy online" mosaic) — matching on the subtitle, rather than just
     * taking the first step, avoids grabbing one of those instead.
     *
     * @param  array<string, mixed>|null  $pageProps
     */
    private function resolveTerms(?array $pageProps): ?string
    {
        $terms = $pageProps['data']['termsAndConditions'] ?? null;

        if (is_string($terms) && trim(strip_tags($terms)) !== '') {
            return $this->htmlToPlainText($terms);
        }

        return $this->resolveTermsFromAccordion($pageProps);
    }

    /**
     * @param  array<string, mixed>|null  $pageProps
     */
    private function resolveTermsFromAccordion(?array $pageProps): ?string
    {
        $components = $pageProps['data']['pages'][0]['components'] ?? null;

        if (! is_array($components)) {
            return null;
        }

        foreach ($components as $component) {
            if (! is_array($component) || ($component['type'] ?? null) !== 'multiaccordionv2') {
                continue;
            }

            $steps = is_array($component['steps'] ?? null) ? $component['steps'] : [];

            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }

                $subtitle = is_string($step['subtitle'] ?? null) ? strip_tags($step['subtitle']) : '';

                if (mb_stripos($subtitle, 'condiciones') === false) {
                    continue;
                }

                $text = is_string($step['text'] ?? null) ? $this->htmlToPlainText($step['text']) : '';

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    private function htmlToPlainText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;
        $text = implode("\n", array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== ''));

        return trim($text);
    }

    /**
     * Looks for an `offer` component matching this promotion's id among the
     * page's components. Brand pages that group several promo variants
     * together (`promotionGroupRenderer`) don't have one — `validDays` and
     * `paymentMethods` are simply left empty in that case, exactly as if
     * the page fetch itself had failed.
     *
     * @param  array<string, mixed>|null  $pageProps
     * @return array{validDays: string[], paymentMethods: string[]}
     */
    private function resolveOfferDetail(?array $pageProps, string $promotionId): array
    {
        $empty = ['validDays' => [], 'paymentMethods' => []];
        $components = $pageProps['data']['pages'][0]['components'] ?? null;

        if (! is_array($components)) {
            return $empty;
        }

        $offers = array_values(array_filter(
            $components,
            fn ($component) => is_array($component) && ($component['type'] ?? null) === 'offer',
        ));

        if ($offers === []) {
            return $empty;
        }

        $matched = null;

        foreach ($offers as $offer) {
            if (($offer['extraData']['promotions'][0]['_id'] ?? null) === $promotionId) {
                $matched = $offer;
                break;
            }
        }

        // Every sampled single-offer page only ever has the one offer
        // component, so falling back to it when the id doesn't line up
        // (rather than discarding the data) is a safe default.
        $matched ??= $offers[0];

        $extraData = is_array($matched['extraData'] ?? null) ? $matched['extraData'] : [];

        return [
            'validDays' => $this->mapActiveDays(is_array($extraData['activeDays'] ?? null) ? $extraData['activeDays'] : []),
            'paymentMethods' => $this->normalizeOfferPaymentMethods(is_array($extraData['paymentMethods'] ?? null) ? $extraData['paymentMethods'] : []),
        ];
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return string[]
     */
    private function mapActiveDays(array $codes): array
    {
        $dayNames = [
            'MO' => 'Lunes', 'TU' => 'Martes', 'WE' => 'Miércoles', 'TH' => 'Jueves',
            'FR' => 'Viernes', 'SA' => 'Sábado', 'SU' => 'Domingo',
        ];

        $days = [];

        foreach ($codes as $code) {
            if (is_string($code) && isset($dayNames[$code])) {
                $days[] = $dayNames[$code];
            }
        }

        return $days;
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return string[]
     */
    private function normalizeOfferPaymentMethods(array $codes): array
    {
        $labels = [];

        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $labels[] = ucwords(str_replace(['-', '_'], ' ', $code));
            }
        }

        return array_values(array_unique($labels));
    }
}
