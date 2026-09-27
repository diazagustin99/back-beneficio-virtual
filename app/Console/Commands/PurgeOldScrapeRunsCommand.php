<?php

namespace App\Console\Commands;

use App\Actions\Scraping\PurgeOldScrapeRunsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scrape-runs:purge')]
#[Description('Delete ScrapeRun rows older than 30 days and their promotion_sources (cascade) — promotions and their snapshots are kept, only the now-purged run link is cleared.')]
class PurgeOldScrapeRunsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PurgeOldScrapeRunsAction $action): int
    {
        $count = $action->handle();

        $this->info("{$count} scrape run(s) purgado(s).");

        return self::SUCCESS;
    }
}
