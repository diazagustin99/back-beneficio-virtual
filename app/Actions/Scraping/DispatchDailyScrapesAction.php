<?php

namespace App\Actions\Scraping;

use App\Enums\ScrapeRunStatus;
use App\Jobs\ScrapeWalletJob;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use App\Services\Scraping\WalletScraperRegistry;

class DispatchDailyScrapesAction
{
    public function __construct(
        private readonly WalletScraperRegistry $registry,
    ) {}

    /**
     * @param  string[]|null  $walletSlugs  Restrict to these wallet slugs; null = every active wallet.
     */
    public function handle(?array $walletSlugs = null, string $triggeredBy = 'schedule'): void
    {
        Wallet::query()
            ->active()
            ->when($walletSlugs !== null, fn ($query) => $query->whereIn('slug', $walletSlugs))
            ->get()
            // An attribution-only wallet (e.g. a bank with no scraper of its
            // own, only ever receiving what `ModoScraper` confirms is
            // exclusive to it) has nothing of its own to scrape — dispatching
            // it anyway would just fail every single day with
            // UnregisteredWalletScraperException.
            ->filter(fn (Wallet $wallet) => $this->registry->has($wallet))
            ->each(function (Wallet $wallet) use ($triggeredBy) {
                $scrapeRun = ScrapeRun::create([
                    'wallet_id' => $wallet->id,
                    'status' => ScrapeRunStatus::Pending,
                    'triggered_by' => $triggeredBy,
                ]);

                ScrapeWalletJob::dispatch($wallet, $scrapeRun);
            });
    }
}
