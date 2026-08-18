<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one character can actually teleport with. Item anchors depend on
     * that character's level and quest progression, and the skill anchor on
     * whether it trained Teleport — so availability is never global.
     */
    public function up(): void
    {
        Schema::create('character_teleport_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teleport_anchor_id')->constrained()->cascadeOnDelete();
            /** The character's own item instance id — the value for itemids[]. */
            $table->unsignedBigInteger('iid')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'teleport_anchor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_teleport_anchors');
    }
};
