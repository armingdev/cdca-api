<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cast-on-start selection a run applied to its whole fleet.
     *
     * The authoritative selection still lives per character in
     * character_skills.cast_on_start — this column only records what the run
     * was launched with, so "repeat that run" can pre-fill the picker without
     * reading every participant back.
     */
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('skill_ids')->nullable()->after('require_circumspect');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('skill_ids');
        });
    }
};
