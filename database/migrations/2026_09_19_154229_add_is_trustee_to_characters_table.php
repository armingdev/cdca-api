<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * accounts.php lists trustees — characters another RGA shared with this
     * one — right next to the RGA's own, so a main with many trustees fills
     * the fleet with characters that are not really part of it. The roster
     * sync flags them from ajax/trusteeList.php so lists can filter them out.
     */
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->boolean('is_trustee')->default(false)->after('rga_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('is_trustee');
        });
    }
};
