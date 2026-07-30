<?php

namespace App\Scrapers\Uala;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\ExtractsContentfulRichText;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;

/**
 * uala.com.ar/promociones is a Next.js (Pages Router) page rendered
 * server-side — every promotion is already embedded as Contentful entries in
 * the `__NEXT_DATA__` script tag, so no separate API call is needed. The
 * site sits behind Incapsula, which is why a realistic User-Agent
 * (MakesHttpRequests) is required to get a real response instead of a
 * challenge page.
 */
class UalaScraper implements WalletScraperInterface
{
    use ExtractsContentfulRichText;
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string URL = 'https://www.uala.com.ar/promociones';

    public function walletSlug(): string
    {
        return 'uala';
    }

    public function scrape(): iterable
    {
        $html = $this->http()->get(self::URL)->throw()->body();
        $data = $this->extractNextData($html);

        if ($data === null) {
            return;
        }

        foreach ($this->findPromotionsArray($data) as $item) {
            $fields = $item['fields'] ?? null;

            if (! is_array($fields)) {
                continue;
            }

            $dto = $this->fieldsToDto($fields);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractNextData(string $html): ?array
    {
        if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return null;
        }

        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Locates the promotions list by shape (items with `fields.urlDeLaPromocion`)
     * instead of a hardcoded array path, so a Contentful content reshuffle on
     * the page doesn't silently break the scraper.
     *
     * @param  array<mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    private function findPromotionsArray(array $node, int $depth = 0): array
    {
        if ($depth > 20) {
            return [];
        }

        if (array_is_list($node) && $node !== [] && is_array($node[0] ?? null) && isset($node[0]['fields']['urlDeLaPromocion'])) {
            return $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->findPromotionsArray($value, $depth + 1);

                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function fieldsToDto(array $fields): ?PromotionDTO
    {
        $merchant = $fields['brand'] ?? null;
        $title = $fields['previewTitle'] ?? null;

        if (! is_string($merchant) || $merchant === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $specs = $fields['PromotionSpecs'][0]['fields'] ?? [];
        $specs = is_array($specs) ? $specs : [];

        $category = $fields['PromotionCategory'][0]['fields']['Category'] ?? null;
        $percentage = $this->extractPercentage($title);
        $isCashback = in_array('Cashback', $this->normalizeStringList($fields['promotionType'] ?? null), true);

        $legal = $specs['promotionLegal'] ?? null;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $title,
            merchantIconUrl: $this->resolveIconUrl($fields['promotionLogo'] ?? null),
            category: is_string($category) ? $category : null,
            description: $this->composeDescription($fields, $specs),
            discountPercentage: $isCashback ? null : $percentage,
            cashbackPercentage: $isCashback ? $percentage : null,
            reimbursementCap: $this->parseCurrencyAmount(is_string($specs['PromotionCashback'] ?? null) ? $specs['PromotionCashback'] : null),
            validDays: $this->normalizeStringList($specs['PromotionDays'] ?? null),
            endDate: $this->parseSpanishDate(is_string($specs['PromotionDate'] ?? null) ? $specs['PromotionDate'] : null),
            terms: $this->resolveTerms($legal),
            url: is_string($specs['ButtonHref'] ?? null) ? $specs['ButtonHref'] : null,
            externalId: is_string($fields['urlDeLaPromocion'] ?? null) ? $fields['urlDeLaPromocion'] : null,
            paymentMethods: $this->normalizeStringList($specs['promotionPayment'] ?? null),
            rawPayload: $fields,
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $specs
     */
    private function composeDescription(array $fields, array $specs): ?string
    {
        $parts = array_filter([
            $fields['previewDescription'] ?? null,
            $specs['previewDescripcion'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /**
     * `promotionLegal` is inconsistent across entries in practice: some are a
     * Contentful rich-text document (array), most observed live are already
     * a plain string. Handle both instead of assuming one shape.
     */
    private function resolveTerms(mixed $legal): ?string
    {
        if (is_string($legal)) {
            $trimmed = trim($legal);

            return $trimmed === '' ? null : $trimmed;
        }

        return $this->contentfulRichTextToPlainText(is_array($legal) ? $legal : null);
    }

    /**
     * Contentful assets carry a protocol-relative URL (`//images.ctfassets.net/...`).
     */
    private function resolveIconUrl(mixed $promotionLogo): ?string
    {
        $url = $promotionLogo['fields']['file']['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return str_starts_with($url, '//') ? 'https:'.$url : $url;
    }

    private function extractPercentage(string $text): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $text, $matches)) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    private function parseCurrencyAmount(?string $value): ?float
    {
        if ($value === null || ! preg_match('/([\d.,]+)/', $value, $matches)) {
            return null;
        }

        $normalized = str_replace('.', '', $matches[1]);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item) => is_string($item) && $item !== ''));
    }
}
