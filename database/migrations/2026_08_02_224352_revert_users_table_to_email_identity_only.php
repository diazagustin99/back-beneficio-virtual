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
        // Split into separate calls: dropping an indexed column together with
        // the rest in a single SQLite table-rebuild loses track of the index,
        // producing "no such column: token" (SQLite runs the test suite; MySQL
        // is unaffected, but it needs to work on both).
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['token', 'wants_notifications']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('token', 40)->unique()->nullable()->after('email');
            $table->boolean('wants_notifications')->default(false)->after('token');
        });
    }
};
