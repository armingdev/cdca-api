<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per (attacker, defender) attack cooldown — the 60-minute rule the game
     * enforces ("You can only attack someone once every 60 minutes").
     *
     * Keyed on the opponent's game player id rather than a name, because
     * players rename freely. Seeded from /attacklog?mode=out on run start so
     * the engine never burns a request on a target it already knows is
     * blocked, and corrected from a refusal's stated elapsed minutes.
     */
    public function up(): void
    {
        Schema::create('attack_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('opponent_player_id');
            $table->string('opponent_name')->nullable();
            $table->timestamp('last_attacked_at');
            $table->timestamp('next_attackable_at');
            $table->string('source')->default('observed');
            $table->timestamps();

            $table->unique(['character_id', 'opponent_player_id']);
            // The engine's hot query: "who can this character hit right now?"
            $table->index(['character_id', 'next_attackable_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attack_cooldowns');
    }
};
