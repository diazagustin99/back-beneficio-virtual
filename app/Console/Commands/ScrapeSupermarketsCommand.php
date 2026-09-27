<?php

namespace App\Console\Commands;

use App\Actions\Scraping\DispatchDailySupermarketScrapesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('promotions:scrape-supermarkets')]
#[Description('Dispatch a scrape job for each supermarket in config/merchant_scrapers.php due for one — but only once every wallet has finished scraping for today.')]
class ScrapeSupermarketsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DispatchDailySupermarketScrapesAction $dispatch): int
    {
        $dispatched = $dispatch->handle();

        $this->info($dispatched
            ? 'Scrape dispatched for every configured supermarket due for one.'
            : 'Nothing dispatched: either a wallet scrape is still pending/running today, or no supermarket is due for a scrape yet.');

        return self::SUCCESS;
    }
}
