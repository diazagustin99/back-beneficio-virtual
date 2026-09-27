<?php

namespace App\Actions\Scraping;

use App\Enums\ScrapeRunStatus;
use App\Jobs\ScrapeWalletJob;
use App\Models\Wallet;
use App\Services\Scraping\ScrapeCooldown;
use App\Services\Scraping\WalletScraperRegistry;

class DispatchDailyScrapesAction
{
    public function __construct(
        private readonly WalletScraperRegistry $registry,
        private readonly ScrapeCooldown $cooldown,
    ) {}

    /**
     * @param  string[]|null  $walletSlugs  Restrict to these wallet slugs; null = every active wallet.
     */
    public function handle(?array $walletSlugs = null, string $triggeredBy = 'schedule'): void
    {
        // An explicit --wallet list is someone asking for that wallet right
        // now — the 5-day/retry-on-failure cooldown (see
        // plans/0026-scraping-cada-5-dias.md) only applies to the broad daily
        // sweep, never to a targeted manual request.
        $bypassCooldown = $walletSlugs !== null;

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
            ->filter(fn (Wallet $wallet) => $bypassCooldown || $this->cooldown->isDue($wallet))
            ->each(function (Wallet $wallet) use ($triggeredBy) {
                $scrapeRun = $wallet->scrapeRuns()->create([
                    'status' => ScrapeRunStatus::Pending,
                    'triggered_by' => $triggeredBy,
                ]);

                ScrapeWalletJob::dispatch($wallet, $scrapeRun);
            });
    }
}
