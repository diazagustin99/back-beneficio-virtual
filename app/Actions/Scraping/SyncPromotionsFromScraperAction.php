<?php

namespace App\Actions\Scraping;

use App\DTOs\PromotionDTO;
use App\Enums\ScrapeRunStatus;
use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class SyncPromotionsFromScraperAction
{
    public function __construct(
        private readonly UpsertPromotionFromDtoAction $upsert,
        private readonly DeactivatePromotionsNotInRunAction $deactivate,
    ) {}

    /**
     * Persistence core for one scraper's run. `$source` is whatever
     * triggered it — a `Wallet` (its own schedule) or a `Merchant` (a
     * supermarket's own page, see `MerchantScraperInterface`) — and every
     * DTO's `walletSlug` says where it ends up:
     * - When `$source` is a `Wallet`, that's almost always the source
     *   itself. The one exception is MODO: a DTO whose `walletSlug` names a
     *   *different*, already-known wallet (a bank MODO says this specific
     *   promo is exclusive to — see `ModoScraper`) is stored under that
     *   bank's wallet instead, tagged with MODO as its source. Every other
     *   wallet scraper's DTOs carry their own wallet's slug, so
     *   `$targetWallet === $source` for them and this behaves exactly as
     *   before.
     * - When `$source` is a `Merchant`, there's no such shortcut — a
     *   merchant is never itself a valid promotion wallet, so every DTO's
     *   `walletSlug` must already be resolvable (guaranteed by
     *   `ResolveWalletFromBankNameAction` running inside the merchant
     *   scraper itself, before the DTO is even built). If one somehow isn't,
     *   that's a bug upstream, not something to paper over here — the DTO
     *   fails like any other and the rest of the batch still runs.
     *
     * @param  Wallet|Merchant  $source
     * @param  iterable<int, PromotionDTO>  $dtos
     */
    public function handle(Model $source, ScrapeRun $scrapeRun, iterable $dtos): void
    {
        $walletsBySlug = $source instanceof Wallet ? [$source->slug => $source] : [];
        $seenPromotionIdsByWalletId = [];
        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($dtos as $dto) {
            $counts['total']++;

            try {
                $targetWallet = $this->resolveTargetWallet($walletsBySlug, $source, $dto->walletSlug);
                $result = $this->upsert->handle($targetWallet, $dto, $scrapeRun);
                $seenPromotionIdsByWalletId[$targetWallet->id] ??= new Collection;
                $seenPromotionIdsByWalletId[$targetWallet->id]->push($result['promotion']->id);
                $counts[$result['status']]++;
            } catch (Throwable $e) {
                $counts['failed']++;
                report($e);
            }
        }

        $status = $this->resolveStatus($counts);

        // A run that didn't fully succeed can't be trusted to say "this
        // promotion is gone" just because it wasn't in *this* run's own
        // seen list — that starves the "not seen" comparison of a
        // complete picture and would wrongly deactivate promotions that
        // are still genuinely active, only missed because of the run's
        // own failure (see plans/0024-desactivacion-en-scrapes-incompletos.md).
        // DeactivateExpiredPromotionsAction (its own daily schedule) still
        // catches anything truly past its end date regardless of whether
        // any scrape ran today.
        $deactivated = $status === ScrapeRunStatus::Success
            ? $this->deactivateAcrossTargetWallets($source, $seenPromotionIdsByWalletId)
            : 0;

        $scrapeRun->update([
            'status' => $status,
            'finished_at' => now(),
            'promotions_total' => $counts['total'],
            'promotions_created' => $counts['created'],
            'promotions_updated' => $counts['updated'],
            'promotions_unchanged' => $counts['unchanged'],
            'promotions_deactivated' => $deactivated,
            'promotions_failed' => $counts['failed'],
        ]);
    }

    /**
     * @param  array<string, Wallet>  $walletsBySlug  Cache, mutated in place
     *                                                so a repeated slug across
     *                                                many DTOs costs one query.
     * @param  Wallet|Merchant  $source
     */
    private function resolveTargetWallet(array &$walletsBySlug, Model $source, string $walletSlug): Wallet
    {
        if ($source instanceof Wallet && $walletSlug === $source->slug) {
            return $source;
        }

        if (isset($walletsBySlug[$walletSlug])) {
            return $walletsBySlug[$walletSlug];
        }

        $wallet = Wallet::query()->where('slug', $walletSlug)->first();

        if ($wallet === null) {
            // Safe only for a wallet source (see the class docblock) — a
            // merchant source reaching here means the scraper's own wallet
            // resolution has a bug, and silently attributing the promo to
            // the merchant itself would corrupt `promotions.wallet_id`.
            if (! ($source instanceof Wallet)) {
                throw new RuntimeException("No wallet found for slug [{$walletSlug}] while syncing merchant scraper [{$source->slug}].");
            }

            $wallet = $source;
        }

        return $walletsBySlug[$walletSlug] = $wallet;
    }

    /**
     * Deactivates every wallet this source ever attributed a promotion to —
     * not just the ones with a DTO in *this* run — so a bank-exclusive
     * promo that stops appearing (or stops being exclusive) still gets
     * deactivated under that bank's wallet even though this run sent it zero
     * DTOs for that wallet.
     *
     * @param  Wallet|Merchant  $source
     * @param  array<int, Collection<int, int>>  $seenPromotionIdsByWalletId
     */
    private function deactivateAcrossTargetWallets(Model $source, array $seenPromotionIdsByWalletId): int
    {
        $targetWalletIds = array_unique([
            ...array_keys($seenPromotionIdsByWalletId),
            ...Promotion::query()
                ->whereHas('lastScrapeRun', fn ($query) => $query
                    ->where('scrapeable_type', $source->getMorphClass())
                    ->where('scrapeable_id', $source->getKey()))
                ->distinct()
                ->pluck('wallet_id')
                ->all(),
        ]);

        if ($targetWalletIds === []) {
            return 0;
        }

        $targetWallets = Wallet::query()->whereIn('id', $targetWalletIds)->get()->keyBy('id');
        $deactivated = 0;

        foreach ($targetWalletIds as $walletId) {
            $deactivated += $this->deactivate->handle(
                $targetWallets[$walletId],
                $source,
                $seenPromotionIdsByWalletId[$walletId] ?? new Collection,
            );
        }

        return $deactivated;
    }

    /**
     * A run that saw nothing at all (a dead site, a broken selector) isn't
     * "successful" just because nothing individually threw — treated the
     * same as every DTO failing, so it never gets to look at an empty seen
     * list and wrongly conclude every one of this wallet's promotions is
     * gone (see plans/0024-desactivacion-en-scrapes-incompletos.md).
     *
     * @param  array{total: int, failed: int}  $counts
     */
    private function resolveStatus(array $counts): ScrapeRunStatus
    {
        return match (true) {
            $counts['total'] === 0 => ScrapeRunStatus::Failed,
            $counts['failed'] === $counts['total'] => ScrapeRunStatus::Failed,
            $counts['failed'] > 0 => ScrapeRunStatus::Partial,
            default => ScrapeRunStatus::Success,
        };
    }
}
