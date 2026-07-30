<?php

namespace App\Scrapers\PersonalPay;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;

/**
 * personal.com.ar/pay/beneficios is a Next.js (Pages Router) page whose
 * initial SSR render only embeds the *first* page of benefits (100 items)
 * in `__NEXT_DATA__`. The full catalog (204 items, verified live) is
 * exposed by the same JSON API the page itself calls when a category
 * filter is applied: `GET /pay/api/benefits?sourceSection=web&offset=N`.
 *
 * The endpoint's page size is fixed at 100 regardless of any `limit`
 * param (tested: `limit=500` is silently ignored) — pagination works by
 * passing the `data.meta.offset` from a response back as the next
 * request's `offset`, and stops once a page returns zero benefits.
 */
class PersonalPayScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string API_URL = 'https://www.personal.com.ar/pay/api/benefits';

    private const string REFERER = 'https://www.personal.com.ar/pay/beneficios';

    /**
     * Safety cap so a pagination bug can't turn this into an unbounded
     * loop. The observed catalog is ~204 items (3 pages of 100); this
     * leaves generous headroom for growth.
     */
    private const int MAX_PAGES = 50;

    public function walletSlug(): string
    {
        return 'personal_pay';
    }

    public function scrape(): iterable
    {
        $offset = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->http()
                ->withHeaders(['Referer' => self::REFERER])
                ->get(self::API_URL, [
                    'sourceSection' => 'web',
                    'offset' => $offset,
                ])
                ->throw()
                ->json();

            $benefits = is_array($response) ? ($response['data']['benefits'] ?? []) : [];

            if (! is_array($benefits) || $benefits === []) {
                break;
            }

            foreach ($benefits as $benefit) {
                if (! is_array($benefit)) {
                    continue;
                }

                $dto = $this->benefitToDto($benefit);

                if ($dto !== null) {
                    yield $dto;
                }
            }

            $nextOffset = $response['data']['meta']['offset'] ?? null;

            if (! is_int($nextOffset) || $nextOffset <= $offset) {
                break;
            }

            $offset = $nextOffset;
        }
    }

    /**
     * @param  array<string, mixed>  $benefit
     */
    private function benefitToDto(array $benefit): ?PromotionDTO
    {
        $merchant = $benefit['title'] ?? null;
        $title = $benefit['benefitValue'] ?? $benefit['discounts'] ?? null;

        if (! is_string($merchant) || $merchant === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $isCashback = ($benefit['typeCode'] ?? null) === 'Cashback';
        $percentage = $this->extractPercentage(is_string($benefit['discounts'] ?? null) ? $benefit['discounts'] : $title);

        $levels = is_array($benefit['levels'] ?? null) ? $benefit['levels'] : [];
        $minimumPurchase = $levels[0]['paymentMin'] ?? null;

        $legal = $benefit['legal'] ?? null;
        $legal = is_string($legal) && $legal !== '' ? $legal : null;
        // `documentTyc` is empirically always empty in this feed, but it's the
        // dedicated "terms & conditions document" field, so it's kept as a
        // fallback in case a future benefit populates it instead of `legal`.
        $documentTyc = $benefit['documentTyc'] ?? null;
        $documentTyc = is_string($documentTyc) && $documentTyc !== '' ? $documentTyc : null;

        $iconUrl = $benefit['image'] ?? $benefit['partnerImage'] ?? null;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $title,
            merchantIconUrl: is_string($iconUrl) && $iconUrl !== '' ? $iconUrl : null,
            category: is_string($benefit['heading'] ?? null) && $benefit['heading'] !== '' ? $benefit['heading'] : null,
            description: $this->composeDescription($benefit),
            discountPercentage: $isCashback ? null : $percentage,
            cashbackPercentage: $isCashback ? $percentage : null,
            reimbursementCap: $this->parseCurrencyAmount(is_string($benefit['limitAmount'] ?? null) ? $benefit['limitAmount'] : null),
            minimumPurchase: is_numeric($minimumPurchase) ? (float) $minimumPurchase : null,
            validDays: $this->normalizeStringList($benefit['days'] ?? null),
            endDate: $this->parseIsoDate(is_string($benefit['dueDate'] ?? null) ? $benefit['dueDate'] : null),
            terms: $legal ?? $documentTyc,
            externalId: isset($benefit['id']) ? (string) $benefit['id'] : null,
            paymentMethods: $this->extractPaymentMethodNames($benefit['paymentMethods'] ?? null),
            rawPayload: $benefit,
        );
    }

    /**
     * @param  array<string, mixed>  $benefit
     */
    private function composeDescription(array $benefit): ?string
    {
        $parts = array_filter([
            $benefit['description'] ?? null,
            $benefit['name'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');

        return $parts === [] ? null : implode(' — ', $parts);
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
    private function extractPaymentMethodNames(mixed $paymentMethods): array
    {
        if (! is_array($paymentMethods)) {
            return [];
        }

        $names = array_map(
            fn ($method) => is_array($method) ? ($method['name'] ?? null) : null,
            $paymentMethods,
        );

        return array_values(array_filter($names, fn ($name) => is_string($name) && $name !== ''));
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
