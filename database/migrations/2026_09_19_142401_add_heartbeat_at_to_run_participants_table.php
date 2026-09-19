<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A worker that is killed outright (OOM, SIGKILL, a lost Redis) cannot
     * tell anyone, so its participant used to sit at "Running" until the
     * queue's retry_after redelivered the job 2h10m later. The live job stamps
     * this column every few seconds instead, and outwar:runs-recover-stalled
     * re-drives any in-flight participant whose stamp has gone quiet.
     */
    public function up(): void
    {
        Schema::table('run_participants', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable()->after('resume_at');
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::table('run_participants', function (Blueprint $table) {
            $table->dropIndex(['status', 'heartbeat_at']);
            $table->dropColumn('heartbeat_at');
        });
    }
};
