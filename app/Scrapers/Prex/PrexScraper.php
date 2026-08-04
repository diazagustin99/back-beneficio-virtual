<?php

namespace App\Scrapers\Prex;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Throwable;

/**
 * prexcard.com.ar/promociones is a Next.js (Pages Router) page rendered
 * server-side — the whole catalog (13 promotions, confirmed live; by far
 * the smallest catalog of any wallet scraped so far) is already embedded as
 * `props.pageProps.promotionsData` in the `__NEXT_DATA__` script tag, no
 * separate API call needed for the listing.
 *
 * The listing only has a short blurb (`promotion_description`, e.g. "Del 3
 * al 30 de agosto") and a CMS publish window (`publication_start`/
 * `publication_end`) — no structured discount %, merchant, category, or
 * legal terms. Each promo's own detail page (`/promociones/{slug}`, also
 * server-rendered `__NEXT_DATA__`, confirmed live) embeds
 * `currentBenefit.description`: the full "Términos y Condiciones" as rich
 * HTML, converted to plain text here. This second fetch is enrichment
 * only — a failure just leaves `terms` null, it never aborts the scrape.
 *
 * Neither page exposes a merchant or category field at all: the merchant is
 * derived from the promo's own `name` slug (e.g. "smiles-millas" → "Smiles
 * Millas"), and `category` stays null — same accepted limitation as Mercado
 * Pago (see its scraper docblock).
 *
 * `publication_start`/`publication_end` are a CMS visibility window, not
 * necessarily the promo's own legal validity dates (SKY's landing, for
 * example, stays published from 2025-05-07 to 2026-09-01 even though its
 * own terms only cover purchases made between the 3rd and the 30th of
 * August) — still the closest structured signal Prex exposes, so it's used
 * as-is rather than trying to regex a date range out of free-text terms.
 */
class PrexScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string LISTING_URL = 'https://www.prexcard.com.ar/promociones';

    private const string DETAIL_URL = 'https://www.prexcard.com.ar/promociones/';

    private const string IMAGE_CDN_BASE = 'https://d28a49jheumby6.cloudfront.net';

    public function walletSlug(): string
    {
        return 'prex';
    }

    public function scrape(): iterable
    {
        $html = $this->http()->get(self::LISTING_URL)->throw()->body();
        $data = $this->extractNextData($html);
        $promotions = $data['props']['pageProps']['promotionsData'] ?? null;

        if (! is_array($promotions)) {
            return;
        }

        foreach ($promotions as $item) {
            if (! is_array($item)) {
                continue;
            }

            $dto = $this->itemToDto($item);

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
     * @param  array<string, mixed>  $item
     */
    private function itemToDto(array $item): ?PromotionDTO
    {
        if ((int) ($item['approved'] ?? 0) !== 1 || (int) ($item['is_promotion'] ?? 0) !== 1) {
            return null;
        }

        $title = $item['promotion_title'] ?? null;
        $slug = $item['name'] ?? null;

        if (! is_string($title) || $title === '' || ! is_string($slug) || $slug === '') {
            return null;
        }

        $isCashback = preg_match('/reintegro|cashback/iu', $title) === 1;
        $percentage = $this->extractPercentage($title);
        $description = $item['promotion_description'] ?? null;
        $iconPath = $item['promotion_image'] ?? null;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $this->slugToMerchantName($slug),
            title: $title,
            merchantIconUrl: is_string($iconPath) && $iconPath !== '' ? $this->resolveImageUrl($iconPath) : null,
            description: is_string($description) && $description !== '' ? $description : null,
            discountPercentage: $isCashback ? null : $percentage,
            cashbackPercentage: $isCashback ? $percentage : null,
            startDate: $this->parseIsoDate(is_string($item['publication_start'] ?? null) ? $item['publication_start'] : null),
            endDate: $this->parseIsoDate(is_string($item['publication_end'] ?? null) ? $item['publication_end'] : null),
            terms: $this->fetchTerms($slug),
            url: self::DETAIL_URL.$slug,
            externalId: isset($item['id']) ? (string) $item['id'] : $slug,
            rawPayload: $item,
        );
    }

    /**
     * Stage 2 enrichment for a single promo. Never throws — any failure
     * (network error, unexpected page shape) yields `null`, and the caller
     * simply leaves `terms` null.
     */
    private function fetchTerms(string $slug): ?string
    {
        try {
            $html = $this->http()->get(self::DETAIL_URL.$slug)->throw()->body();
        } catch (Throwable) {
            return null;
        }

        $data = $this->extractNextData($html);
        $description = $data['props']['pageProps']['currentBenefit']['description'] ?? null;

        if (! is_string($description) || $description === '') {
            return null;
        }

        $text = $this->htmlToPlainText($description);

        return $text === '' ? null : $text;
    }

    private function htmlToPlainText(string $html): string
    {
        $html = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</h2>', '</h3>'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;
        $text = implode("\n", array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== ''));

        return trim($text);
    }

    /**
     * Neither the listing nor the detail page names a merchant explicitly —
     * the promo's own slug is the closest thing to one (e.g. "diaonline" →
     * "Diaonline", "smiles-millas" → "Smiles Millas").
     */
    private function slugToMerchantName(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * `promotion_image` is a CDN-relative path (e.g.
     * "/web/landings/sky/imagen_presentacion/portada.png"); the detail
     * page's own `desktop_image`/`mobile_image` for the same promo confirm
     * the CDN host live.
     */
    private function resolveImageUrl(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : self::IMAGE_CDN_BASE.$path;
    }

    private function extractPercentage(string $text): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $text, $matches)) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }
}
