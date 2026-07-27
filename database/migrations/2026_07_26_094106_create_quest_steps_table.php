<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quest_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('npc');
            $table->text('message')->nullable();
            $table->json('item_rewards');
            $table->unsignedBigInteger('exp_reward')->nullable();
            $table->text('reply')->nullable();
            $table->timestamps();

            $table->unique(['quest_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quest_steps');
    }
};
