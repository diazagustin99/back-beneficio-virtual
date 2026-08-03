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
        Schema::dropIfExists('merchant_user');
        Schema::dropIfExists('user_wallet');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('merchant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'user_id']);
        });

        Schema::create('user_wallet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'wallet_id']);
        });
    }
};
