<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registry of *other people's* characters — every PvP target we have ever
     * seen, on any list. Populated opportunistically by the hitlist, crew and
     * brawl parsers, so target lists get richer the more we run.
     *
     * `player_id` is the game's own id, the same value our own characters
     * carry as `suid` (profile.php?id={player_id}). Names are mutable, ids are
     * not, so everything downstream keys on the id.
     */
    public function up(): void
    {
        Schema::create('player_characters', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('server_id');
            $table->unsignedInteger('player_id');
            $table->string('name');
            $table->unsignedSmallInteger('level')->nullable();
            $table->foreignId('crew_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attackability')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'player_id']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_characters');
    }
};
