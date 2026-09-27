<?php

namespace App\Services\Scraping;

use App\Enums\ScrapeRunStatus;
use App\Models\Merchant;
use App\Models\Wallet;

/**
 * Decides whether a wallet or merchant is due for a new scrape today, so the
 * daily schedule can skip anything recently scraped that doesn't need
 * revisiting yet — bank/supermarket discount catalogs don't change daily, so
 * scraping every one of them every single day wastes resources for little
 * benefit. See plans/0026-scraping-cada-5-dias.md.
 */
class ScrapeCooldown
{
    private const int SUCCESS_COOLDOWN_DAYS = 5;

    private const int RETRY_AFTER_FAILURE_DAYS = 1;

    public function isDue(Wallet|Merchant $scrapeable): bool
    {
        $latest = $scrapeable->scrapeRuns()->latest('created_at')->first();

        if ($latest === null) {
            return true;
        }

        return match ($latest->status) {
            ScrapeRunStatus::Success => $latest->finished_at->lte(now()->subDays(self::SUCCESS_COOLDOWN_DAYS)),
            // A `Partial` run still failed to process some promotions — same
            // daily-retry treatment as a full failure, not the 5-day cooldown
            // a clean run gets.
            ScrapeRunStatus::Partial, ScrapeRunStatus::Failed => $latest->finished_at->lte(now()->subDays(self::RETRY_AFTER_FAILURE_DAYS)),
            // Already in flight — the job's own ShouldBeUnique guard already
            // prevents a real duplicate anyway.
            ScrapeRunStatus::Pending, ScrapeRunStatus::Running => false,
        };
    }
}
