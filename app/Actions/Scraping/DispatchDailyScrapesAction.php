<?php

namespace App\Actions\Scraping;

use App\Enums\ScrapeRunStatus;
use App\Jobs\ScrapeWalletJob;
use App\Models\ScrapeRun;
use App\Models\Wallet;

class DispatchDailyScrapesAction
{
    /**
     * @param  string[]|null  $walletSlugs  Restrict to these wallet slugs; null = every active wallet.
     */
    public function handle(?array $walletSlugs = null, string $triggeredBy = 'schedule'): void
    {
        Wallet::query()
            ->active()
            ->when($walletSlugs !== null, fn ($query) => $query->whereIn('slug', $walletSlugs))
            ->get()
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