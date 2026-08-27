<?php

namespace App\Jobs;

use App\Actions\Scraping\FilterDuplicateBankDiscountsAction;
use App\Actions\Scraping\SyncPromotionsFromScraperAction;
use App\Enums\ScrapeRunStatus;
use App\Exceptions\Scraping\UnregisteredMerchantScraperException;
use App\Models\Merchant;
use App\Models\ScrapeRun;
use App\Services\Scraping\MerchantScraperRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sibling of `ScrapeWalletJob` for the merchant-scraping pipeline (see
 * `MerchantScraperInterface`) — same body shape (mark running, resolve the
 * scraper, sync), with one extra step in between: every DTO passes through
 * `FilterDuplicateBankDiscountsAction` first, since a supermarket's own page
 * can easily name the same wallet+day+discount a bank's own scraper already
 * created independently.
 */
class ScrapeMerchantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A failed scrape is not safely retryable mid-run; it surfaces via the
     * ScrapeRun row and is picked up again on the next scheduled/manual run.
     */
    public int $tries = 1;

    public int $timeout = 600;

    /**
     * Guards against a manual rerun overlapping the scheduled one for the
     * same merchant, on top of the schedule's own withoutOverlapping().
     */
    public int $uniqueFor = 72000;

    public function __construct(
        public readonly Merchant $merchant,
        public readonly ScrapeRun $scrapeRun,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->merchant->id;
    }

    public function handle(
        MerchantScraperRegistry $registry,
        FilterDuplicateBankDiscountsAction $filterDuplicates,
        SyncPromotionsFromScraperAction $sync,
    ): void {
        $this->scrapeRun->update([
            'status' => ScrapeRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $scraper = $registry->for($this->merchant);
        } catch (UnregisteredMerchantScraperException $e) {
            $this->scrapeRun->update([
                'status' => ScrapeRunStatus::Failed,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        $dtos = $filterDuplicates->handle($this->merchant, $scraper->scrape());

        $sync->handle($this->merchant, $this->scrapeRun, $dtos);
    }

    public function failed(?Throwable $exception): void
    {
        $this->scrapeRun->update([
            'status' => ScrapeRunStatus::Failed,
            'finished_at' => now(),
            'error_message' => $exception?->getMessage(),
        ]);

        Log::error('ScrapeMerchantJob failed', [
            'merchant_id' => $this->merchant->id,
            'scrape_run_id' => $this->scrapeRun->id,
            'exception' => $exception,
        ]);
    }
}
