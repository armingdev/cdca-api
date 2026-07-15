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
        Schema::create('mob_room', function (Blueprint $table) {
            $table->foreignId('mob_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('room_id');
            $table->timestamp('last_seen_at')->nullable();

            $table->primary(['mob_id', 'room_id']);
            $table->index('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mob_room');
    }
};
