<?php

namespace App\Console\Commands;

use App\Actions\Scraping\DispatchDailyScrapesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('promotions:scrape {--wallet=* : Restrict the run to these wallet slugs}')]
#[Description('Dispatch a scrape job for each active wallet (or the given --wallet slugs).')]
class ScrapePromotionsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DispatchDailyScrapesAction $dispatch): int
    {
        $wallets = $this->option('wallet');

        $dispatch->handle(
            walletSlugs: $wallets !== [] ? $wallets : null,
            triggeredBy: $wallets !== [] ? 'manual' : 'schedule',
        );

        $this->info($wallets !== []
            ? 'Scrape dispatched for: '.implode(', ', $wallets)
            : 'Scrape dispatched for all active wallets.');

        return self::SUCCESS;
    }
}
