<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A battle whose outcome we could not read was being counted as an
     * `error`, which means "the attack did not happen". It did happen — it
     * cost rage — we just could not classify the result page. Lumping the two
     * together made a working PvP run read as a total failure.
     */
    public function up(): void
    {
        Schema::table('run_participants', function (Blueprint $table) {
            $table->unsignedInteger('unknown')->default(0)->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('run_participants', function (Blueprint $table) {
            $table->dropColumn('unknown');
        });
    }
};
