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
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode');
            $table->jsonb('config');
            $table->string('status')->default('pending');
            $table->unsignedInteger('restart_every_minutes')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'restart_every_minutes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
