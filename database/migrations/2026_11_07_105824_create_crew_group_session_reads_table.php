<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail of every time a leader read the shared sessions — the
     * members can see who read their accounts and when. Append-only.
     */
    public function up(): void
    {
        Schema::create('crew_group_session_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->jsonb('rga_ids');
            $table->boolean('verified')->default(false);
            $table->string('ip', 45)->nullable();
            $table->timestamp('read_at')->useCurrent();

            $table->index(['crew_group_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_group_session_reads');
    }
};
