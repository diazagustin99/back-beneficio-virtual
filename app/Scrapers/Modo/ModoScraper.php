<?php

namespace App\Scrapers\Modo;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;

/**
 * modo.com.ar/promos is a Next.js App Router site backed by its own REST
 * API under /promos/api/rewards — by far the richest and largest catalog of
 * the six wallets (roughly 780 promotions across ~8 pages of 100).
 *
 * The listing endpoint (`/rewards/slots`) has no terms/legal field at all
 * (checked every key on a live card, including the nested `content` object)
 * — full T&C likely live behind a per-promo detail page/endpoint that isn't
 * called from this listing and wasn't reverse-engineered. `terms` stays null
 * here. `content.image.primary_image` is used as the merchant icon.
 */
class ModoScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string CATEGORIES_URL = 'https://www.modo.com.ar/promos/api/rewards/categories';

    private const string SLOTS_URL = 'https://www.modo.com.ar/promos/api/rewards/slots';

    private const string REFERER = 'https://www.modo.com.ar/promos';

    private const int PAGE_SIZE = 100;

    /**
     * @var array<string, string>
     */
    private const array DAY_LETTERS = [
        'L' => 'Lunes', 'M' => 'Martes', 'X' => 'Miércoles', 'J' => 'Jueves',
        'V' => 'Viernes', 'S' => 'Sábado', 'D' => 'Domingo',
    ];

    public function walletSlug(): string
    {
        return 'modo';
    }

    public function scrape(): iterable
    {
        $categories = $this->fetchCategoryMap();

        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->fetchPage($page);
            $cards = $response['data']['cards'] ?? [];
            $totalPages = (int) ($response['metadata']['pagination']['total_pages'] ?? 1);

            foreach (is_array($cards) ? $cards : [] as $card) {
                if (! is_array($card)) {
                    continue;
                }

                $dto = $this->cardToDto($card, $categories);

                if ($dto !== null) {
                    yield $dto;
                }
            }

            $page++;
        } while ($page <= $totalPages);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(int $page): array
    {
        $response = $this->http()
            ->withHeaders(['Referer' => self::REFERER])
            ->get(self::SLOTS_URL, [
                'slots' => 'web-modo-hub-mas-promos',
                'limit' => self::PAGE_SIZE,
                'page' => $page,
                'search_text' => '',
                'source' => 'web_modo',
                'origin' => 'web_modo',
                'fcalcstatus' => 'running',
                'slot_info' => 'true',
                'categories' => '',
                'banks' => '',
                'user_bank_ids' => '',
            ])
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * @return array<int, string>
     */
    private function fetchCategoryMap(): array
    {
        $categories = $this->http()
            ->withHeaders(['Referer' => self::REFERER])
            ->get(self::CATEGORIES_URL, ['subcategories' => 'true'])
            ->throw()
            ->json();

        if (! is_array($categories)) {
            return [];
        }

        $map = [];

        foreach ($categories as $category) {
            if (is_array($category) && isset($category['id'], $category['title']) && is_string($category['title'])) {
                $map[(int) $category['id']] = $category['title'];
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<int, string>  $categories
     */
    private function cardToDto(array $card, array $categories): ?PromotionDTO
    {
        $merchant = $card['where'] ?? null;
        $title = $card['title'] ?? null;

        if (! is_string($merchant) || $merchant === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $mapCategoryId = $card['categories_whitelist']['categories'][0]['map_category'] ?? null;
        $category = is_numeric($mapCategoryId) ? ($categories[(int) $mapCategoryId] ?? null) : null;

        $description = $card['short_description'] ?? null;
        $externalId = $card['promo_id'] ?? $card['id'] ?? null;
        $iconUrl = $card['content']['image']['primary_image'] ?? null;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $title,
            merchantIconUrl: is_string($iconUrl) && $iconUrl !== '' ? $iconUrl : null,
            category: $category,
            description: is_string($description) && $description !== '' ? $description : null,
            discountPercentage: $this->extractPercentage($title),
            validDays: $this->decodeDaysOfWeek(is_string($card['days_of_week'] ?? null) ? $card['days_of_week'] : null),
            startDate: $this->parseIsoDate(is_string($card['start_date'] ?? null) ? $card['start_date'] : null),
            endDate: $this->parseIsoDate(is_string($card['stop_date'] ?? null) ? $card['stop_date'] : null),
            externalId: is_string($externalId) ? $externalId : null,
            paymentMethods: $this->normalizePaymentMethods($card['debit_list'] ?? null, $card['credit_list'] ?? null),
            rawPayload: $card,
        );
    }

    private function extractPercentage(string $title): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $title, $matches)) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    /**
     * @return string[]
     */
    private function decodeDaysOfWeek(?string $code): array
    {
        if ($code === null || $code === '') {
            return [];
        }

        $days = [];

        foreach (str_split($code) as $letter) {
            if (isset(self::DAY_LETTERS[$letter])) {
                $days[] = self::DAY_LETTERS[$letter];
            }
        }

        return $days;
    }

    /**
     * @return string[]
     */
    private function normalizePaymentMethods(mixed $debit, mixed $credit): array
    {
        $methods = array_merge(
            is_array($debit) ? $debit : [],
            is_array($credit) ? $credit : [],
        );

        $labels = array_map(
            fn ($method) => is_string($method) ? ucwords(str_replace('_', ' ', $method)) : null,
            $methods,
        );

        return array_values(array_unique(array_filter($labels, fn ($label) => is_string($label) && $label !== '')));
    }
}
