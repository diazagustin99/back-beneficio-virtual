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
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('token', 40)->unique()->after('email');
            $table->boolean('wants_notifications')->default(false)->after('token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['token', 'wants_notifications']);
            $table->string('name')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
