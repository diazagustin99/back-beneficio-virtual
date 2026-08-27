<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('promotions:scrape')
    ->dailyAt('03:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('categories:merge-duplicates')
    ->dailyAt('04:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('merchants:merge-duplicates')
    ->dailyAt('04:15')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('promotions:merge-duplicates')
    ->dailyAt('04:30')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// The 45-minute gap after promotions:scrape (03:00) is just a reasonable
// buffer, not the real guarantee — DispatchDailySupermarketScrapesAction
// itself checks that no wallet ScrapeRun is still pending/running today
// before dispatching anything, and safely no-ops (retried on the next tick)
// if wallet scraping is still going. See plans/0021-scrapping-supermercados.md.
Schedule::command('promotions:scrape-supermarkets')
    ->dailyAt('04:45')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('promotions:deactivate-expired')
    ->dailyAt('05:30')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('notifications:daily-merchant-discounts')
    ->dailyAt('08:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
