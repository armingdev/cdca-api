<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A battle only knew its character, so "what did this run drop" could not
     * be answered, and a run's battle list showed every fight its characters
     * ever had. Null for battles fought outside a run (console commands) and
     * for everything recorded before this column existed.
     */
    public function up(): void
    {
        Schema::table('battle_events', function (Blueprint $table) {
            $table->foreignId('run_id')->nullable()->after('character_id')->constrained()->nullOnDelete();

            // Replaces the foreign key's single-column index need: per-run
            // drop totals filter on run_id and group on drop_name.
            $table->index(['run_id', 'drop_name']);
        });
    }

    public function down(): void
    {
        Schema::table('battle_events', function (Blueprint $table) {
            $table->dropIndex(['run_id', 'drop_name']);
            $table->dropConstrainedForeignId('run_id');
        });
    }
};
