<?php

namespace App\Scrapers\NaranjaX;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use DateTimeInterface;
use Throwable;

/**
 * naranjax.com/promociones/resultados is an Angular SPA — the page HTML
 * carries no data, everything is fetched client-side from
 * bkn-promotions.naranjax.com.
 *
 * The full catalog lives behind `POST /binder/filter`, which requires a
 * geoposition + radius (`zoom`) — there's no query that skips geolocation
 * entirely. In practice a single, very large radius from a central
 * Argentine point returns a near-complete national catalog: the API caps
 * `info.total` at 10000 regardless of radius (confirmed live at both
 * 2000km and 5000km from Córdoba), so one big-radius sweep from the
 * country's geographic center is both simpler and gives materially better
 * coverage than stitching together many per-city queries.
 *
 * This feed has no terms/legal field at all (checked every key on a live
 * response, including inside `plans`), so `terms` stays null here.
 *
 * `pageOptions.size` has an undocumented hard ceiling: 50 consistently
 * works, 60+ consistently 400s with a generic "UNKNOWN_ERROR" regardless of
 * the geoposition — verified repeatedly, it isn't random. On top of that,
 * the endpoint is also independently flaky under sustained use (an
 * in-bounds request can still occasionally 400). A failed page therefore
 * stops pagination gracefully instead of throwing: whatever pages were
 * already fetched this run still get persisted normally, rather than
 * losing the whole run (and its deactivation pass) to one bad page.
 */
class NaranjaXScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string FILTER_URL = 'https://bkn-promotions.naranjax.com/bff-promotions-web/api/binder/filter';

    private const string REFERER = 'https://www.naranjax.com/promociones/resultados';

    /**
     * 50 is the largest page size that reliably avoids the endpoint's
     * undocumented size ceiling (60+ consistently 400s).
     */
    private const int PAGE_SIZE = 50;

    /**
     * Safety cap so a pagination bug can't turn this into an unbounded
     * loop. The observed `info.total` cap is 10000, i.e. 200 pages of 50 —
     * this leaves headroom above that.
     */
    private const int MAX_PAGES = 220;

    /** Córdoba — roughly the geographic center of Argentina. */
    private const string LATITUDE = '-34.61315';

    private const string LONGITUDE = '-58.37723';

    /** Comfortably covers the whole country (Jujuy to Ushuaia) from Córdoba. */
    private const string RADIUS = '1000km';

    /**
     * @var array<string, string>
     */
    private const array PAYMENT_METHOD_LABELS = [
        'credito' => 'Crédito',
        'debito' => 'Débito',
        'dinero' => 'Dinero en cuenta',
        'visa' => 'Visa crédito',
        'master' => 'Master crédito',
        'amex' => 'Amex crédito',
    ];

    /**
     * @var array<int, string>
     */
    private const array WEEKDAY_NAMES = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    public function walletSlug(): string
    {
        return 'naranja_x';
    }

    public function scrape(): iterable
    {
        $page = 1;

        do {
            try {
                $response = $this->http()
                    ->withHeaders(['Referer' => self::REFERER])
                    ->post(self::FILTER_URL, [
                        'filters' => [
                            'geoposition' => [
                                'latitude' => self::LATITUDE,
                                'longitude' => self::LONGITUDE,
                                'zoom' => self::RADIUS,
                            ],
                            'paymentMethods' => [
                                'DINERO',
                                'DEBITO',
                                'CREDITO',
                                'VISA',
                            ],
                            'purchaseModes' => ['ONLINE', 'IN_STORE'],
                            'validOnWeekdays' => ['ALL_DAYS'],
                        ],
                        'pageOptions' => [
                            'page' => $page,
                            'size' => self::PAGE_SIZE,
                        ],
                    ])
                    ->throw()
                    ->json();
            } catch (Throwable $e) {
                // See class docblock: this endpoint is flaky independent of
                // the payload. Stop pagination here rather than losing the
                // whole run — the pages already fetched still get persisted.
                report($e);

                break;
            }

            $items = is_array($response) ? ($response['data'] ?? []) : [];
            $itemsInPage = is_array($response) ? (int) ($response['info']['itemsInPage'] ?? 0) : 0;

            foreach (is_array($items) ? $items : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $dto = $this->itemToDto($item);

                if ($dto !== null) {
                    yield $dto;
                }
            }

            $page++;
        } while ($itemsInPage >= self::PAGE_SIZE && $page <= self::MAX_PAGES);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemToDto(array $item): ?PromotionDTO
    {
        $merchant = $item['commerceName'] ?? null;
        $title = $item['title'] ?? null;

        if (! is_string($merchant) || $merchant === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $plans = is_array($item['plans'] ?? null) ? $item['plans'] : [];
        $plan = $this->selectCurrentPlan($plans);

        $iconUrl = $item['logo'] ?? null;
        $url = $item['fullUrl'] ?? $item['url'] ?? null;
        $categoryName = $item['category']['name'] ?? null;
        $description = $item['subtitle'] ?? null;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $title,
            merchantIconUrl: is_string($iconUrl) && $iconUrl !== '' ? $iconUrl : null,
            category: is_string($categoryName) && $categoryName !== '' ? $categoryName : null,
            description: is_string($description) && $description !== '' ? $description : null,
            discountPercentage: $this->extractPercentage($title),
            installments: $this->extractInstallments($title),
            validDays: $this->resolveValidDays($plan),
            startDate: $this->parseSlashDate($plan['days']['dateFrom'] ?? null),
            endDate: $this->parseSlashDate($plan['days']['dateTo'] ?? null),
            url: is_string($url) && $url !== '' ? $url : null,
            externalId: is_string($item['id'] ?? null) ? $item['id'] : null,
            paymentMethods: $this->normalizePaymentMethods($item['paymentMethods'] ?? null),
            rawPayload: $item,
        );
    }

    /**
     * A commerce entry can have several `plans` (e.g. different date
     * windows). Prefer the one the API itself marks as currently active.
     *
     * @param  array<int, mixed>  $plans
     * @return array<string, mixed>
     */
    private function selectCurrentPlan(array $plans): array
    {
        foreach ($plans as $plan) {
            if (is_array($plan) && ($plan['status'] ?? null) === 'CURRENT') {
                return $plan;
            }
        }

        return is_array($plans[0] ?? null) ? $plans[0] : [];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return string[]
     */
    private function resolveValidDays(array $plan): array
    {
        $weekdays = $plan['days']['weekdaysApplied'] ?? null;

        if (! is_array($weekdays)) {
            return [];
        }

        $days = array_map(
            fn ($day) => self::WEEKDAY_NAMES[(int) $day] ?? null,
            $weekdays,
        );

        return array_values(array_unique(array_filter($days)));
    }

    /**
     * Dates arrive as "DD/MM/YYYY" strings.
     */
    private function parseSlashDate(mixed $value): ?DateTimeInterface
    {
        if (! is_string($value) || ! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $matches)) {
            return null;
        }

        return $this->parseIsoDate("{$matches[3]}-{$matches[2]}-{$matches[1]}");
    }

    private function extractPercentage(string $text): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $text, $matches)) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    private function extractInstallments(string $text): ?int
    {
        if (! preg_match('/(\d+)\s*cuotas/iu', $text, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return string[]
     */
    private function normalizePaymentMethods(mixed $methods): array
    {
        if (! is_array($methods)) {
            return [];
        }

        $labels = array_map(
            fn ($method) => is_string($method)
                ? (self::PAYMENT_METHOD_LABELS[mb_strtolower($method)] ?? ucfirst($method))
                : null,
            $methods,
        );

        return array_values(array_filter($labels, fn ($label) => is_string($label) && $label !== ''));
    }
}
