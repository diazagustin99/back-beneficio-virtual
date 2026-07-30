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
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('triggered_by', 20)->default('schedule');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('promotions_total')->default(0);
            $table->unsignedInteger('promotions_created')->default(0);
            $table->unsignedInteger('promotions_updated')->default(0);
            $table->unsignedInteger('promotions_unchanged')->default(0);
            $table->unsignedInteger('promotions_deactivated')->default(0);
            $table->unsignedInteger('promotions_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index(['wallet_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
