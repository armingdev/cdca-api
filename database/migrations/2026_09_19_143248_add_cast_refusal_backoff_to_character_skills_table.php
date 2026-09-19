<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A skill the game refuses to cast (it needs a parameter we do not send,
     * it is a once-a-day skill already used, …) was retried on every buff
     * pass — two characters wrote 700+ warnings in a week that way. The
     * refusal is remembered here so each one backs off further than the last.
     */
    public function up(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->unsignedSmallInteger('cast_refusals')->default(0)->after('last_cast_at');
            $table->timestamp('cast_refused_until')->nullable()->after('cast_refusals');
        });
    }

    public function down(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->dropColumn(['cast_refusals', 'cast_refused_until']);
        });
    }
};
