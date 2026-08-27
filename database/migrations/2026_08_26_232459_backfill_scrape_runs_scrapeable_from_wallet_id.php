<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every existing scrape_runs row was created by a wallet scraper (the
     * merchant-scraper pipeline is new) — backfill them all to the 'wallet'
     * morph alias before the next migration drops wallet_id.
     */
    public function up(): void
    {
        DB::table('scrape_runs')
            ->whereNotNull('wallet_id')
            ->update([
                'scrapeable_type' => 'wallet',
                'scrapeable_id' => DB::raw('wallet_id'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('scrape_runs')
            ->where('scrapeable_type', 'wallet')
            ->update([
                'scrapeable_type' => null,
                'scrapeable_id' => null,
            ]);
    }
};
