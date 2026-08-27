<?php

namespace App\Scrapers\Supermarkets;

use App\Actions\Scraping\ResolveWalletFromBankNameAction;
use App\Contracts\Scrapers\MerchantScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\ConvertsIsoWeekDays;
use App\Scrapers\Concerns\MakesHttpRequests;
use Illuminate\Support\Carbon;

/**
 * Cencosud (Vea, Jumbo, Disco — Disco has no scraper yet, see
 * plans/0021-scrapping-supermercados.md) exposes every "por banco" discount
 * as a single VTEX Master Data document shared across all of its
 * storefronts. Each concrete scraper just points at its own host and its
 * own `websites` key to filter the shared feed — ported from
 * proyecto-scrapping-super's own CencosudBankDiscountScraper, confirmed
 * live against the same endpoint.
 *
 * Confirmed live: the feed's own "bank" field isn't always a bank at all —
 * card networks ("Visa y Master"), a government installment program ("Plan
 * Ahora 3"), and other noise sit right alongside real banks (see
 * config/bank_wallet_aliases.php's `skip` list) — `ResolveWalletFromBankNameAction`
 * returns null for those, and this scraper just drops that one discount
 * rather than create a garbage wallet.
 */
abstract class CencosudBankDiscountScraper implements MerchantScraperInterface
{
    use ConvertsIsoWeekDays;
    use MakesHttpRequests;

    // VTEX account hosting the shared Master Data document (not storefront-specific).
    private const string ACCOUNT_NAME = 'jumboargentina';

    public function __construct(
        private readonly ResolveWalletFromBankNameAction $resolveWallet,
    ) {}

    /** Storefront origin, e.g. "https://www.vea.com.ar". */
    abstract protected function apiHost(): string;

    /** Value found in each entry's `websites` array that scopes it to this storefront. */
    abstract protected function websiteKey(): string;

    abstract protected function sourceUrl(): string;

    public function scrape(): iterable
    {
        $response = $this->http()
            ->get($this->apiHost().'/api/dataentities/JN/documents/bankDiscount', [
                '_fields' => 'value,id',
                'an' => self::ACCOUNT_NAME,
            ])
            ->throw();

        $entries = json_decode((string) $response->json('value'), true);

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry) || ! $this->appliesToThisStore($entry) || $this->isExpired($entry)) {
                continue;
            }

            $dto = $this->entryToDto($entry);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function appliesToThisStore(array $entry): bool
    {
        return in_array($this->websiteKey(), $entry['websites'] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isExpired(array $entry): bool
    {
        $dateEnd = (int) ($entry['dateEnd'] ?? 0);

        return $dateEnd > 0 && $dateEnd < now()->timestamp;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function entryToDto(array $entry): ?PromotionDTO
    {
        $bankLabel = trim((string) ($entry['banks'][0]['name'] ?? ''));

        if ($bankLabel === '') {
            return null;
        }

        $wallet = $this->resolveWallet->handle($bankLabel);

        if ($wallet === null) {
            return null;
        }

        [$discountPercentage, $installments] = $this->resolveDiscount($entry);
        $days = collect($entry['days'] ?? [])->map(fn ($day) => (int) $day)->all();

        $title = $this->buildTitle($discountPercentage, $installments, $wallet->name);

        return new PromotionDTO(
            walletSlug: $wallet->slug,
            merchantName: $this->merchantName(),
            title: $title,
            category: null,
            description: $this->stringOrNull($entry['info'] ?? null),
            discountPercentage: $discountPercentage,
            installments: $installments,
            validDays: $this->resolveValidDaysFromIso($days !== [] ? $days : null),
            startDate: $this->toDate($entry['dateStart'] ?? null),
            endDate: $this->toDate($entry['dateEnd'] ?? null),
            terms: $this->stringOrNull($entry['legals'] ?? null),
            url: $this->sourceUrl(),
            externalId: sha1(implode('|', [
                $this->merchantName(), $bankLabel, $entry['discount'] ?? '', $entry['discountText'] ?? '',
                $entry['dateStart'] ?? '', $entry['dateEnd'] ?? '', $entry['info'] ?? '',
            ])),
            rawPayload: $entry,
        );
    }

    /**
     * The feed's own `discount` number means an installment count, not a
     * percentage, whenever `discountText` says so ("cuotas sin interés") —
     * confirmed live on Vea's own feed. Never both at once.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: ?float, 1: ?int}
     */
    private function resolveDiscount(array $entry): array
    {
        $discount = $entry['discount'] ?? null;
        $discountText = mb_strtolower((string) ($entry['discountText'] ?? ''));

        if ($discount === null || $discount === '' || ! is_numeric($discount)) {
            return [null, null];
        }

        if (str_contains($discountText, 'cuota')) {
            return [null, (int) $discount];
        }

        return [(float) $discount, null];
    }

    private function buildTitle(?float $discountPercentage, ?int $installments, string $walletName): string
    {
        $parts = [];

        if ($discountPercentage !== null) {
            $parts[] = rtrim(rtrim(sprintf('%.2f', $discountPercentage), '0'), '.').'% de descuento';
        }

        if ($installments !== null) {
            $parts[] = "{$installments} cuotas sin interés";
        }

        $suffix = $parts !== [] ? implode(' y ', $parts) : 'Descuento bancario';

        return "{$suffix} con {$walletName}";
    }

    private function toDate(int|string|null $timestamp): ?Carbon
    {
        if ($timestamp === null || (int) $timestamp === 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $timestamp);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
