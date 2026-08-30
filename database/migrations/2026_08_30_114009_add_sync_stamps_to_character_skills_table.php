<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the game last *told us* about each window, as opposed to when we
     * last cast. A null buff_until/recharge_until is ambiguous on its own —
     * "never read" and "the server says it is not active" look identical —
     * so the local last_cast_at + duration estimate kept firing over fresh
     * server readings and silently skipped skills the player had selected.
     * With these stamps a reading newer than the last cast is authoritative.
     */
    public function up(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->timestamp('buff_synced_at')->nullable()->after('buff_until');
            $table->timestamp('recharge_synced_at')->nullable()->after('recharge_until');
        });
    }

    public function down(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->dropColumn(['buff_synced_at', 'recharge_synced_at']);
        });
    }
};
