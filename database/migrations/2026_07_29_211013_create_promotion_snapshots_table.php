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
        Schema::create('promotion_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scrape_run_id')->nullable()->constrained('scrape_runs')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['promotion_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_snapshots');
    }
};
