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
        Schema::table('character_skills', function (Blueprint $table) {
            $table->unsignedSmallInteger('trained_level')->default(0)->after('skill_id');
            $table->unsignedSmallInteger('bonus_level')->default(0)->after('trained_level');
            $table->unsignedInteger('current_rage_cost')->nullable()->after('bonus_level');
            $table->unsignedInteger('current_cooldown_minutes')->nullable()->after('current_rage_cost');
            $table->unsignedInteger('current_duration_minutes')->nullable()->after('current_cooldown_minutes');
            $table->timestamp('recharge_until')->nullable()->after('last_cast_at');
            $table->timestamp('buff_until')->nullable()->after('recharge_until');
            $table->timestamp('synced_at')->nullable()->after('buff_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->dropColumn([
                'trained_level',
                'bonus_level',
                'current_rage_cost',
                'current_cooldown_minutes',
                'current_duration_minutes',
                'recharge_until',
                'buff_until',
                'synced_at',
            ]);
        });
    }
};
