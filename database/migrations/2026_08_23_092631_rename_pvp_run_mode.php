<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PvP split into five modes, so the old catch-all `pvp` value no longer
     * resolves. It was the name-list mode, which is now `pvp-attack-list`;
     * without this, existing runs blow up on RunMode::from().
     */
    public function up(): void
    {
        DB::table('runs')->where('mode', 'pvp')->update(['mode' => 'pvp-attack-list']);
    }

    public function down(): void
    {
        DB::table('runs')->where('mode', 'pvp-attack-list')->update(['mode' => 'pvp']);
    }
};
