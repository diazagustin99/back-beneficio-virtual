<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table) {
            // The FK has to go first — MySQL refuses to drop either index
            // below while it still needs one of them to satisfy the FK.
            $table->dropForeign(['wallet_id']);
            $table->dropIndex(['wallet_id', 'status']);
            $table->dropIndex(['wallet_id', 'created_at']);
            $table->dropColumn('wallet_id');
        });
    }

    /**
     * Reverse the migrations. Best-effort only: a run sourced from a
     * merchant scraper has no wallet_id to restore, so the recreated
     * column is nullable here even though the original was not — rolling
     * back past this point on a database that already has merchant-sourced
     * runs needs a forward fix migration, not a clean rollback.
     */
    public function down(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->foreignId('wallet_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('scrape_runs')
            ->where('scrapeable_type', 'wallet')
            ->update(['wallet_id' => DB::raw('scrapeable_id')]);

        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->index(['wallet_id', 'status']);
            $table->index(['wallet_id', 'created_at']);
        });
    }
};
