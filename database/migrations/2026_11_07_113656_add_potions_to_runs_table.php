<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Potions a run uses: buff potions kept active before combat (like
     * cast-on-start skills), and rage potions drunk instead of parking for
     * the hourly rage tick — at most `max_rage_potions` per run cycle.
     * Both are potions.id lists; rage potions are tried in the given order.
     */
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->jsonb('buff_potion_ids')->nullable()->after('skill_ids');
            $table->jsonb('rage_potion_ids')->nullable()->after('buff_potion_ids');
            $table->unsignedSmallInteger('max_rage_potions')->default(0)->after('rage_potion_ids');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['buff_potion_ids', 'rage_potion_ids', 'max_rage_potions']);
        });
    }
};
