<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * world.php?teleport=1 lands in the character's *home* tavern, which is
     * re-homed with world.php?teleportupdate=1&tavern={roomId}. Room 258 is
     * only the default, so the destination has to be tracked per character.
     *
     * Unconstrained like current_room_id — the value is the game's room id and
     * may name a room we have not recorded yet.
     */
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('home_tavern_room_id')->nullable()->after('current_room_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('home_tavern_room_id');
        });
    }
};
