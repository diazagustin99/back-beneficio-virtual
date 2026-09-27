<?php

namespace App\Actions\Scraping;

use App\Models\ScrapeRun;

class PurgeOldScrapeRunsAction
{
    private const int RETENTION_DAYS = 30;

    /**
     * Hard-deletes every `ScrapeRun` older than the retention window, along
     * with everything that only exists to describe that one run — real
     * database foreign-key constraints do the actual cleanup, not
     * application code:
     * - `promotion_sources` (each source scraper's raw payload for that run —
     *   the single biggest table in the database, and never read by any code
     *   path) cascade-deletes with it.
     * - `promotion_snapshots.scrape_run_id` and `promotions.last_scrape_run_id`
     *   are set null instead of cascading — a promotion's own history and the
     *   promotion itself are never touched by this, only the now-meaningless
     *   pointer to which purged run last produced them.
     *
     * Safe even for a wallet still actively scraped: with the 5-day
     * cooldown (see `ScrapeCooldown`, plans/0026-scraping-cada-5-dias.md), a
     * healthy wallet's promotions get a fresh `last_scrape_run_id` at least
     * every 5 days, well inside the 30-day window — only genuinely stale
     * runs ever get purged.
     */
    public function handle(): int
    {
        return ScrapeRun::query()
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }
}
