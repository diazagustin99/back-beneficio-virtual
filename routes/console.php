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

// Una hora después del scrape diario, para que las categorías nuevas que
// haya creado esa corrida (si trajeron un texto "variante" no visto antes)
// queden fusionadas con su categoría canónica antes de que alguien las vea.
Schedule::command('categories:merge-duplicates')
    ->dailyAt('04:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
