<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The member's explicit opt-in: this RGA's session may be read by the
     * group's leader. Deleting the RGA, the sharing user or the group drops
     * the grant. `created_at` is when it was shared.
     */
    public function up(): void
    {
        Schema::create('crew_group_rgas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rga_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['crew_group_id', 'rga_id']);
            $table->index('rga_id');
            $table->index('shared_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_group_rgas');
    }
};
