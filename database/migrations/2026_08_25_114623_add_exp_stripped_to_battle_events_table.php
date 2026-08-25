<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Experience taken off the defender on a PvP win.
     *
     * This is the number the weekly Open PvP Tournament ranks players by
     * ("experience stripped"), and the battle page reports it separately from
     * what we gained — so record it rather than inferring it.
     */
    public function up(): void
    {
        Schema::table('battle_events', function (Blueprint $table) {
            $table->unsignedBigInteger('exp_stripped')->nullable()->after('exp_gained');
        });
    }

    public function down(): void
    {
        Schema::table('battle_events', function (Blueprint $table) {
            $table->dropColumn('exp_stripped');
        });
    }
};
