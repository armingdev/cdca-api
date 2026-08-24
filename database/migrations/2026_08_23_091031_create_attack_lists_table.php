<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-authored PvP target lists, mirroring quest_lists/quest_list_items.
     *
     * Targets are added by name (that is what the user knows); the player id
     * is resolved on first search and cached, after which the id is
     * authoritative.
     */
    public function up(): void
    {
        Schema::create('attack_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('server_id')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('attack_list_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attack_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->unsignedInteger('player_id')->nullable();
            $table->timestamps();

            $table->unique(['attack_list_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attack_list_targets');
        Schema::dropIfExists('attack_lists');
    }
};
