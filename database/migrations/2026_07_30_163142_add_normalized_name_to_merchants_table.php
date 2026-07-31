<?php

use App\Models\Merchant;
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
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('normalized_name', 191)->nullable()->after('slug');
            $table->index('normalized_name');
        });

        Merchant::query()->orderBy('id')->chunkById(500, function ($merchants) {
            foreach ($merchants as $merchant) {
                $merchant->timestamps = false;
                $merchant->normalized_name = Merchant::normalize($merchant->name);
                $merchant->saveQuietly();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropIndex(['normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
