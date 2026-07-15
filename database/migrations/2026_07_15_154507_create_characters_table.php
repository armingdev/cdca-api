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
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rga_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('suid');
            $table->unsignedTinyInteger('server_id');
            $table->string('name');
            $table->unsignedSmallInteger('level')->nullable();
            $table->unsignedBigInteger('rage')->nullable();
            $table->unsignedBigInteger('exp')->nullable();
            $table->string('crew')->nullable();
            $table->unsignedInteger('current_room_id')->nullable();
            $table->timestamp('last_stats_at')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'suid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
