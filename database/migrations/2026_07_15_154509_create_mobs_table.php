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
        Schema::create('mobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('game_mob_id')->nullable()->index();
            $table->string('name')->index();
            $table->unsignedInteger('level')->nullable();
            $table->unsignedInteger('rage_cost')->nullable();
            $table->unsignedTinyInteger('type')->nullable();
            $table->boolean('can_form')->default(false);
            $table->string('image')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobs');
    }
};
