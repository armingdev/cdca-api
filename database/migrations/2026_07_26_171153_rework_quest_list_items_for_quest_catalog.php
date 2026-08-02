<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quest-list items now reference the crawled quest catalog instead of a
     * free-typed game quest id + NPC name (the giver comes from the quest).
     * Pre-deployment rework — no data migration needed.
     */
    public function up(): void
    {
        // Old rows reference game quest ids, not catalog rows — unrecoverable.
        DB::table('quest_list_items')->delete();

        Schema::table('quest_list_items', function (Blueprint $table) {
            $table->dropColumn(['quest_id', 'npc_name']);
        });

        Schema::table('quest_list_items', function (Blueprint $table) {
            $table->foreignId('quest_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Catalog references don't translate back to raw game quest ids —
        // same one-way rule as up().
        DB::table('quest_list_items')->delete();

        Schema::table('quest_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quest_id');
        });

        Schema::table('quest_list_items', function (Blueprint $table) {
            $table->unsignedInteger('quest_id');
            $table->string('npc_name');
        });
    }
};
