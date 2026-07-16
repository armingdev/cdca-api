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
        Schema::create('quest_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('quest_id');
            $table->string('npc_name');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['quest_list_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quest_list_items');
    }
};
