<?php

namespace App\Console\Commands;

use App\Actions\Scraping\DispatchDailySupermarketScrapesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('promotions:scrape-supermarkets')]
#[Description('Dispatch a scrape job for each supermarket in config/merchant_scrapers.php — but only once every wallet has finished scraping for today.')]
class ScrapeSupermarketsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DispatchDailySupermarketScrapesAction $dispatch): int
    {
        $dispatched = $dispatch->handle();

        $this->info($dispatched
            ? 'Scrape dispatched for every configured supermarket.'
            : 'Skipped: a wallet scrape is still pending or running today — try again once it finishes.');

        return self::SUCCESS;
    }
}
