<?php

use App\Models\Wallet;
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
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('normalized_name', 191)->nullable()->after('slug');
            $table->index('normalized_name');
        });

        Wallet::query()->orderBy('id')->chunkById(500, function ($wallets) {
            foreach ($wallets as $wallet) {
                $wallet->timestamps = false;
                $wallet->normalized_name = Wallet::normalize($wallet->name);
                $wallet->saveQuietly();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropIndex(['normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
