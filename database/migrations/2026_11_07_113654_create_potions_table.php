<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The potion catalog, harvested from the game's item database
     * (database/data/potions.json) and extended whenever a backpack shows a
     * potion it does not know. `kind`, `duration_minutes` and `rage_amount`
     * are derived from the free-text description (PotionEffectParser); the
     * live time left always comes from the game, never from here.
     */
    public function up(): void
    {
        Schema::create('potions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('game_item_id')->unique();
            $table->string('name');
            $table->string('image')->nullable();
            $table->unsignedSmallInteger('level')->default(0);
            $table->boolean('player_bound')->default(false);
            $table->text('description');
            $table->string('kind', 10)->index();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('rage_amount')->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('potions');
    }
};
