<?php

namespace App\Actions\Scraping;

use App\DTOs\PromotionDTO;
use App\Enums\ScrapeRunStatus;
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
     * Wallet-agnostic persistence core. Everything it knows about the source
     * comes from `$wallet` and the DTOs — it never branches on which wallet
     * it's syncing.
     *
     * @param  iterable<int, PromotionDTO>  $dtos
     */
    public function handle(Wallet $wallet, ScrapeRun $scrapeRun, iterable $dtos): void
    {
        $seenPromotionIds = new Collection;
        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($dtos as $dto) {
            $counts['total']++;

            try {
                $result = $this->upsert->handle($wallet, $dto, $scrapeRun);
                $seenPromotionIds->push($result['promotion']->id);
                $counts[$result['status']]++;
            } catch (Throwable $e) {
                $counts['failed']++;
                report($e);
            }
        }

        $deactivated = $this->deactivate->handle($wallet, $seenPromotionIds);

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
