<?php

namespace App\Providers;

use App\Models\Merchant;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ScrapeRun::scrapeable is only ever one of these two, so a short
        // alias in scrape_runs.scrapeable_type instead of the FQCN, which
        // would break if either model were ever moved/renamed. Not
        // `enforceMorphMap()`: other morph relations already exist elsewhere
        // (Preference's notifications, webpush's subscribable) that aren't
        // part of this map and must keep resolving by FQCN as before.
        Relation::morphMap([
            'wallet' => Wallet::class,
            'merchant' => Merchant::class,
        ]);
    }
}
