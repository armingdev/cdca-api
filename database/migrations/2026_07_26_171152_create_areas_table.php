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
        Schema::create('areas', function (Blueprint $table) {
            // Ids come from the xowh seed's Areas catalog, not autoincrement.
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('area_id')->nullable()->index();
            $table->foreign('area_id')->references('id')->on('areas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
        });

        Schema::dropIfExists('areas');
    }
};
