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
        Schema::create('bosses', function (Blueprint $table) {
            // Ids are the game's boss ids (127-137) from the xowh seed, not autoincrement.
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('nick');
            $table->unsignedInteger('rage_to_join');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bosses');
    }
};
