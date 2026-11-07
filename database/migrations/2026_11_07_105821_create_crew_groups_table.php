<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cdca-side crew: a leader plus the users who joined with its invite
     * code. Not the game's `crews` table (game-world data every user sees) —
     * this one exists so members can opt in to sharing their RGA sessions
     * with the leader.
     */
    public function up(): void
    {
        Schema::create('crew_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leader_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('invite_code', 16)->unique();
            $table->timestamps();

            $table->index('leader_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_groups');
    }
};
