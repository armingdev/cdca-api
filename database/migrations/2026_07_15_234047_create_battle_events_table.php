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
        Schema::create('battle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mob_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedBigInteger('battle_id')->nullable()->index();
            $table->string('outcome');
            $table->unsignedBigInteger('exp_gained')->nullable();
            $table->unsignedInteger('gold_gained')->nullable();
            $table->string('drop_name')->nullable()->index();
            $table->string('fail_reason')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['character_id', 'occurred_at']);
            $table->index(['mob_id', 'outcome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battle_events');
    }
};
