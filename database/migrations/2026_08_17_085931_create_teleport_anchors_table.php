<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The global, learned catalog of teleport sources. Rows are shared across
     * characters; which of them a character can actually use lives in
     * character_teleport_anchors (availability is per level/quest progress).
     *
     * Only `item` and `skill` anchors are catalogued: the home tavern and the
     * room-1 hatch are per-character/always-available edges the planner adds
     * itself (a global row could not hold a per-character destination).
     */
    public function up(): void
    {
        Schema::create('teleport_anchors', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->comment('item|skill');
            $table->unsignedInteger('game_item_id')->nullable();
            $table->unsignedInteger('skill_id')->nullable();
            $table->string('name');
            /**
             * Null until observed: an item rollover names an *area*, not a
             * room, and the prose often disagrees with the room name (Key to
             * Industrial District lands in "Cross Roads"). Only a curRoom read
             * after an actual jump may fill this in.
             */
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedSmallInteger('required_level')->nullable();
            $table->unsignedInteger('rage_cost')->default(0);
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->text('description')->nullable();
            $table->string('source')->default('observed');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'game_item_id']);
            $table->unique(['kind', 'skill_id', 'room_id']);
            $table->index('room_id');
            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();

            // No FK on room_id: like characters.current_room_id it is the
            // game's own id, and a teleport can name a room we have not
            // recorded yet (the skill dropdown gives ids we may never have
            // walked).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teleport_anchors');
    }
};
