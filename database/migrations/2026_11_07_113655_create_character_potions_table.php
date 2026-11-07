<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a character holds of each potion and whether it is active, as
     * last read from its potion tab and Current Effects panel.
     *
     * `iid` is the instance id of the stack's next item — it changes after
     * every use, so it is only a hint; activation re-reads the tab.
     * `active_until` is an activation's estimate until the next game read
     * overwrites it with the real time left (or null: not active).
     */
    public function up(): void
    {
        Schema::create('character_potions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('potion_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('iid')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamp('active_until')->nullable();
            $table->timestamp('active_synced_at')->nullable();
            $table->timestamp('last_activated_at')->nullable();
            $table->unsignedTinyInteger('refusals')->default(0);
            $table->timestamp('refused_until')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'potion_id']);
            $table->index('potion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_potions');
    }
};
