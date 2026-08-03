<?php

namespace App\Jobs;

use App\Actions\Scraping\SyncPromotionsFromScraperAction;
use App\Enums\ScrapeRunStatus;
use App\Exceptions\Scraping\UnregisteredWalletScraperException;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use App\Services\Scraping\WalletScraperRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScrapeWalletJob implements ShouldBeUnique, ShouldQueue
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
     * same wallet, on top of the schedule's own withoutOverlapping().
     */
    public int $uniqueFor = 72000;

    public function __construct(
        public readonly Wallet $wallet,
        public readonly ScrapeRun $scrapeRun,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->wallet->id;
    }

    public function handle(WalletScraperRegistry $registry, SyncPromotionsFromScraperAction $sync): void
    {
        $this->scrapeRun->update([
            'status' => ScrapeRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $scraper = $registry->for($this->wallet);
        } catch (UnregisteredWalletScraperException $e) {
            $this->scrapeRun->update([
                'status' => ScrapeRunStatus::Failed,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        $sync->handle($this->wallet, $this->scrapeRun, $scraper->scrape());
    }

    public function failed(?Throwable $exception): void
    {
        $this->scrapeRun->update([
            'status' => ScrapeRunStatus::Failed,
            'finished_at' => now(),
            'error_message' => $exception?->getMessage(),
        ]);

        Log::error('ScrapeWalletJob failed', [
            'wallet_id' => $this->wallet->id,
            'scrape_run_id' => $this->scrapeRun->id,
            'exception' => $exception,
        ]);
    }
}