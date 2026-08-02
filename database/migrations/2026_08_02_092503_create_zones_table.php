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
        Schema::create('zones', function (Blueprint $table) {
            // Ids come from the xowh seed's Zones catalog (0-30), not autoincrement.
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('areas', function (Blueprint $table) {
            $table->unsignedInteger('zone_id')->nullable()->index();
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
        });

        Schema::dropIfExists('zones');
    }
};
