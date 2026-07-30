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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promotion_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('last_scrape_run_id')->nullable()->constrained('scrape_runs')->nullOnDelete();

            $table->string('external_id', 191)->nullable();
            $table->char('identity_hash', 64);

            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->decimal('cashback_percentage', 5, 2)->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->decimal('reimbursement_cap', 12, 2)->nullable();
            $table->decimal('minimum_purchase', 12, 2)->nullable();
            $table->json('valid_days')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->text('terms')->nullable();
            $table->text('url')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();

            $table->timestamps();

            $table->unique(['wallet_id', 'identity_hash']);
            $table->index(['wallet_id', 'is_active']);
            $table->index('starts_at');
            $table->index('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
