<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this account's character list was last read from the game. Backs
     * the debounce on the login-triggered sync, and tells the client whether
     * the roster it is showing is fresh.
     */
    public function up(): void
    {
        Schema::table('rgas', function (Blueprint $table) {
            $table->timestamp('characters_synced_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('rgas', function (Blueprint $table) {
            $table->dropColumn('characters_synced_at');
        });
    }
};
