<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table) {
            // A run's own scraper is either a wallet or a merchant (see
            // MerchantScraperInterface) — polymorphic via a morph map alias
            // ('wallet'/'merchant', registered in AppServiceProvider), not
            // the FQCN. No FK constraint on purpose: the two possible
            // referenced tables can't share one real foreign key, and
            // referential integrity here is an app-level concern (neither
            // Wallet nor Merchant has a delete path today).
            $table->nullableMorphs('scrapeable');

            // Replaces the old (wallet_id, status) index: the ordering
            // check in ScrapeSupermarketsCommand ("is any wallet run still
            // pending/running today?") filters by scrapeable_type + status.
            $table->index(['scrapeable_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->dropIndex(['scrapeable_type', 'status']);
            $table->dropMorphs('scrapeable');
        });
    }
};
