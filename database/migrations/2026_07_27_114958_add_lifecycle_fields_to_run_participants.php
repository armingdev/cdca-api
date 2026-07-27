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
        Schema::table('run_participants', function (Blueprint $table) {
            $table->jsonb('progress')->nullable()->after('last_activity');
            $table->timestamp('resume_at')->nullable()->after('progress');
            $table->uuid('dispatch_token')->nullable()->after('resume_at');
            $table->index(['status', 'resume_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('run_participants', function (Blueprint $table) {
            $table->dropIndex(['status', 'resume_at']);
            $table->dropColumn(['progress', 'resume_at', 'dispatch_token']);
        });
    }
};
