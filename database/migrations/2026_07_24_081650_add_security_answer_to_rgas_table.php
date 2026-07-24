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
        Schema::table('rgas', function (Blueprint $table) {
            // Encrypted cast output is unbounded — must be TEXT.
            $table->text('security_answer')->nullable()->after('cookies');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rgas', function (Blueprint $table) {
            $table->dropColumn('security_answer');
        });
    }
};
