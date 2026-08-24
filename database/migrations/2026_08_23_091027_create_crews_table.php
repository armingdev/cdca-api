<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crews we track for crew-members mode. `game_crew_id` is the id in
     * `crew_profile.php?id={game_crew_id}`, which serves any crew's roster —
     * ours or a rival's.
     */
    public function up(): void
    {
        Schema::create('crews', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('server_id');
            $table->unsignedInteger('game_crew_id');
            $table->string('name');
            $table->string('leader')->nullable();
            $table->unsignedSmallInteger('total_members')->nullable();
            $table->unsignedSmallInteger('average_level')->nullable();
            $table->timestamp('members_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'game_crew_id']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crews');
    }
};
