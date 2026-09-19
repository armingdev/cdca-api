<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A label the user gives a run ("tincture mobs", "sub85 veldara") so a
     * list of runs reads as more than a column of ids and modes.
     */
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('name', 80)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
