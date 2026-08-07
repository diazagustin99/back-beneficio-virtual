<?php

namespace App\Actions\Scraping;

use App\DTOs\PromotionDTO;
use App\Enums\ScrapeRunStatus;
use App\Models\Promotion;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Throwable;

class SyncPromotionsFromScraperAction
{
    public function __construct(
        private readonly UpsertPromotionFromDtoAction $upsert,
        private readonly DeactivatePromotionsNotInRunAction $deactivate,
    ) {}

    /**
     * Persistence core for one scraper's run. `$sourceWallet` is the wallet
     * whose *schedule* triggered this run (and whose scraper produced every
     * DTO) — almost always also where each DTO ends up. The one exception is
     * MODO: a DTO whose `walletSlug` names a *different*, already-known
     * wallet (a bank MODO says this specific promo is exclusive to — see
     * `ModoScraper`) is stored under that bank's wallet instead, tagged with
     * MODO as its source. Every other scraper's DTOs always carry their own
     * wallet's slug, so `$targetWallet === $sourceWallet` for them and this
     * behaves exactly as before.
     *
     * @param  iterable<int, PromotionDTO>  $dtos
     */
    public function handle(Wallet $sourceWallet, ScrapeRun $scrapeRun, iterable $dtos): void
    {
        $walletsBySlug = [$sourceWallet->slug => $sourceWallet];
        $seenPromotionIdsByWalletId = [];
        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($dtos as $dto) {
            $counts['total']++;

            try {
                $targetWallet = $this->resolveTargetWallet($walletsBySlug, $sourceWallet, $dto->walletSlug);
                $result = $this->upsert->handle($targetWallet, $dto, $scrapeRun);
                $seenPromotionIdsByWalletId[$targetWallet->id] ??= new Collection;
                $seenPromotionIdsByWalletId[$targetWallet->id]->push($result['promotion']->id);
                $counts[$result['status']]++;
            } catch (Throwable $e) {
                $counts['failed']++;
                report($e);
            }
        }

        $deactivated = $this->deactivateAcrossTargetWallets($sourceWallet, $seenPromotionIdsByWalletId);

        $scrapeRun->update([
            'status' => $this->resolveStatus($counts),
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
     */
    private function resolveTargetWallet(array &$walletsBySlug, Wallet $sourceWallet, string $walletSlug): Wallet
    {
        if ($walletSlug === $sourceWallet->slug) {
            return $sourceWallet;
        }

        return $walletsBySlug[$walletSlug] ??= Wallet::query()->where('slug', $walletSlug)->first() ?? $sourceWallet;
    }

    /**
     * Deactivates every wallet this source ever attributed a promotion to —
     * not just the ones with a DTO in *this* run — so a bank-exclusive MODO
     * promo that stops appearing (or stops being exclusive) still gets
     * deactivated under that bank's wallet even though this run sent it zero
     * DTOs for that wallet.
     *
     * @param  array<int, Collection<int, int>>  $seenPromotionIdsByWalletId
     */
    private function deactivateAcrossTargetWallets(Wallet $sourceWallet, array $seenPromotionIdsByWalletId): int
    {
        $targetWalletIds = array_unique([
            ...array_keys($seenPromotionIdsByWalletId),
            ...Promotion::query()
                ->whereHas('lastScrapeRun', fn ($query) => $query->where('wallet_id', $sourceWallet->id))
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
                $sourceWallet,
                $seenPromotionIdsByWalletId[$walletId] ?? new Collection,
            );
        }

        return $deactivated;
    }

    /**
     * @param  array{total: int, failed: int}  $counts
     */
    private function resolveStatus(array $counts): ScrapeRunStatus
    {
        return match (true) {
            $counts['total'] > 0 && $counts['failed'] === $counts['total'] => ScrapeRunStatus::Failed,
            $counts['failed'] > 0 => ScrapeRunStatus::Partial,
            default => ScrapeRunStatus::Success,
        };
    }
}
