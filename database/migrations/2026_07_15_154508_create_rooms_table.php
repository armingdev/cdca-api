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
        Schema::create('rooms', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable()->index();
            $table->unsignedInteger('north')->nullable();
            $table->unsignedInteger('east')->nullable();
            $table->unsignedInteger('south')->nullable();
            $table->unsignedInteger('west')->nullable();
            $table->jsonb('doors')->nullable();
            $table->boolean('is_gated')->default(false);
            $table->string('gate_reason')->nullable();
            $table->string('source')->default('spider');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
