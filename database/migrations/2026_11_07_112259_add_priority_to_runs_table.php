<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which queue a run's participants wait in when every run worker is busy
     * (see RunPriority). Existing runs keep the queue they always used.
     */
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('priority', 10)->default('normal')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
