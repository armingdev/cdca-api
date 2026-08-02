<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The behavioural flags come from the xowh seed's Mobs catalog; null means
     * the seed hasn't told us (e.g. a spider-discovered mob), distinct from a
     * known false. Name becomes unique because it is the de facto identity key:
     * both the seed importer and RoomObservationRecorder upsert mobs by name.
     */
    public function up(): void
    {
        Schema::table('mobs', function (Blueprint $table) {
            $table->boolean('attackable')->nullable();
            $table->boolean('talkable')->nullable();
            $table->boolean('spawnable')->nullable();
            $table->boolean('trainer')->nullable();
            $table->boolean('long_respawn')->nullable();
            $table->unsignedInteger('spawn_count')->nullable();
            $table->dropIndex(['name']);
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mobs', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->index('name');
            $table->dropColumn([
                'attackable',
                'talkable',
                'spawnable',
                'trainer',
                'long_respawn',
                'spawn_count',
            ]);
        });
    }
};
