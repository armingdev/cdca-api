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
        Schema::create('quest_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type');
            $table->string('target')->index();
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->unique(['quest_step_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quest_conditions');
    }
};
