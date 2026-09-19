<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run may be told to stop spending rage ahead of an event the user
     * wants a full bar for: which events (see RageReserveEvent), and how many
     * hours before each one starts the run should park.
     */
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('reserve_rage_for')->nullable()->after('skill_ids');
            $table->unsignedSmallInteger('reserve_rage_hours')->default(12)->after('reserve_rage_for');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['reserve_rage_for', 'reserve_rage_hours']);
        });
    }
};
