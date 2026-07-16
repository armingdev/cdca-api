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
        Schema::create('skills', function (Blueprint $table) {
            // id = the game's castskillid (not auto-incremented).
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('school');
            $table->unsignedInteger('rage_cost')->default(0);
            $table->unsignedInteger('cooldown_minutes')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('school');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
