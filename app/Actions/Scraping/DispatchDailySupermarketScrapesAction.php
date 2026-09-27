<?php

namespace App\Actions\Scraping;

use App\Enums\ScrapeRunStatus;
use App\Jobs\ScrapeMerchantJob;
use App\Models\ScrapeRun;
use App\Services\Scraping\ScrapeCooldown;

/**
 * Sibling of `DispatchDailyScrapesAction` for the merchant-scraping pipeline
 * — dispatches one `ScrapeMerchantJob` per `config('merchant_scrapers')`
 * entry due for a scrape (see `ScrapeCooldown`,
 * plans/0026-scraping-cada-5-dias.md), but only once every wallet has
 * finished scraping for today. That ordering isn't a fixed delay (fragile:
 * it'll silently break the moment wallet scraping takes longer than whatever
 * offset was guessed) — it's a real check against `scrape_runs` itself, safe
 * to call as often as the schedule likes: it's a no-op, not an error, while
 * any wallet run is still `pending`/`running` today.
 */
class DispatchDailySupermarketScrapesAction
{
    public function __construct(
        private readonly ResolveMerchantAction $resolveMerchant,
        private readonly ScrapeCooldown $cooldown,
    ) {}

    /**
     * @return bool Whether it actually dispatched at least one job — false
     *              means either a wallet scrape is still pending/running
     *              today (try again on the next schedule tick) or every
     *              configured supermarket is still within its cooldown (see
     *              `ScrapeCooldown`).
     */
    public function handle(string $triggeredBy = 'schedule'): bool
    {
        if ($this->aWalletScrapeIsStillPendingToday()) {
            return false;
        }

        $dispatchedAny = false;

        foreach (config('merchant_scrapers.merchants', []) as $scraperClass) {
            // The scraper's own `merchantName()` — not the config key — is
            // what resolves (and, per the user's request, creates) the
            // comercio, same as `ResolveMerchantAction` already does for
            // every wallet scraper's DTOs. The config key still has to match
            // the resulting merchant's slug, though: that's what
            // `MerchantScraperRegistry::for()` looks up once the job runs.
            $scraper = app($scraperClass);
            $merchant = $this->resolveMerchant->handle($scraper->merchantName());

            if (! $this->cooldown->isDue($merchant)) {
                continue;
            }

            $scrapeRun = $merchant->scrapeRuns()->create([
                'status' => ScrapeRunStatus::Pending,
                'triggered_by' => $triggeredBy,
            ]);

            ScrapeMerchantJob::dispatch($merchant, $scrapeRun);
            $dispatchedAny = true;
        }

        return $dispatchedAny;
    }

    private function aWalletScrapeIsStillPendingToday(): bool
    {
        return ScrapeRun::query()
            ->where('scrapeable_type', 'wallet')
            ->whereIn('status', [ScrapeRunStatus::Pending, ScrapeRunStatus::Running])
            ->whereDate('created_at', now())
            ->exists();
    }
}
