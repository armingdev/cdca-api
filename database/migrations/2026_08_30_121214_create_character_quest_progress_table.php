<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each character has already settled with the game, per quest.
     *
     * Without it a quest-list run has no memory: every start walks the
     * character to all 200 givers in turn just to be told the first 180 are
     * done. This table lets the list skip them before taking a single step,
     * and the clear endpoints let a player throw it away when the game's
     * answer should have changed (a level gate passed, say).
     */
    public function up(): void
    {
        Schema::create('character_quest_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quest_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('recorded_at');
            // Breadcrumbs for a verdict that may not age well — the character's
            // level when an 'unavailable' was recorded, for instance.
            $table->jsonb('context')->nullable();

            $table->unique(['character_id', 'quest_id']);
            $table->index(['character_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_quest_progress');
    }
};
