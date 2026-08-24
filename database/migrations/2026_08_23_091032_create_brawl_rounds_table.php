<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Known Brawl rounds, refreshed from /closedpvp.
     *
     * Windows are fortnightly (Monday 08:00–20:00 game time, UTC-5) and round
     * ids interleave by type — PvP took the odd ids, Faction the even ones —
     * so `type` alone does not identify a round.
     */
    public function up(): void
    {
        Schema::create('brawl_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('server_id');
            $table->unsignedTinyInteger('type');
            $table->unsignedInteger('round_id');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('participant_count')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'type', 'round_id']);
            $table->index(['server_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brawl_rounds');
    }
};
