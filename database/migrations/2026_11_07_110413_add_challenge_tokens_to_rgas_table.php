<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily challenge tokens are per RGA (3 a day, 30 held at most). The
     * scheduler claims them while `auto_claim_tokens` is on; the rest is the
     * last reading of ajax/challenge_status.php, so the client can show the
     * balance and the next claim time without asking the game.
     */
    public function up(): void
    {
        Schema::table('rgas', function (Blueprint $table) {
            $table->boolean('auto_claim_tokens')->default(true)->after('status');
            $table->unsignedSmallInteger('challenge_tokens')->nullable()->after('auto_claim_tokens');
            $table->unsignedInteger('challenge_tokens_purchased')->nullable()->after('challenge_tokens');
            $table->timestamp('challenge_claim_at')->nullable()->after('challenge_tokens_purchased');
            $table->timestamp('challenge_claimed_at')->nullable()->after('challenge_claim_at');
            $table->timestamp('challenge_synced_at')->nullable()->after('challenge_claimed_at');

            $table->index(['auto_claim_tokens', 'challenge_claim_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rgas', function (Blueprint $table) {
            $table->dropIndex(['auto_claim_tokens', 'challenge_claim_at']);
            $table->dropColumn([
                'auto_claim_tokens',
                'challenge_tokens',
                'challenge_tokens_purchased',
                'challenge_claim_at',
                'challenge_claimed_at',
                'challenge_synced_at',
            ]);
        });
    }
};
