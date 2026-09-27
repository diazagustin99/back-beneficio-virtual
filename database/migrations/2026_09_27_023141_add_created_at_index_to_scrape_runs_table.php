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
        // No existing index covers created_at — PurgeOldScrapeRunsAction
        // deletes by it daily, and without this it's a full table scan.
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
