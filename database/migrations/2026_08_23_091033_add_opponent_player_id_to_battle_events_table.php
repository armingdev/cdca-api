<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PvP battles recorded only the opponent's name, which players change at
     * will. The cooldown rule is enforced per player id, so record that too.
     */
    public function up(): void
    {
        Schema::table('battle_events', function (Blueprint $table) {
            $table->unsignedInteger('opponent_player_id')->nullable()->after('opponent_name');
            $table->unsignedSmallInteger('opponent_level')->nullable()->after('opponent_player_id');

            $table->index(['opponent_player_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('battle_events', function (Blueprint $table) {
            $table->dropIndex(['opponent_player_id', 'occurred_at']);
            $table->dropColumn(['opponent_player_id', 'opponent_level']);
        });
    }
};
