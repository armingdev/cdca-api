<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The durable run log. run_participants.last_activity holds only the
     * newest line — every earlier one is overwritten — so after a long run
     * there is no way to tell which quests were skipped or which skills never
     * got cast. This table keeps one row per engine *decision*.
     */
    public function up(): void
    {
        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('level')->default('info');
            $table->string('message', 500);
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at');

            // The run log view (newest first) and its per-participant filter;
            // id is the tie-breaker because many events share a timestamp.
            $table->index(['run_id', 'id']);
            $table->index(['run_participant_id', 'id']);
            $table->index(['run_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_events');
    }
};
