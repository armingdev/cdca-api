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
        Schema::table('battle_events', function (Blueprint $table) {
            $table->string('kind')->default('pve')->after('character_id');
            $table->string('opponent_name')->nullable()->after('mob_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('battle_events', function (Blueprint $table) {
            $table->dropColumn(['kind', 'opponent_name']);
        });
    }
};
