<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who belongs to a crew group. The leader has a row too, so "members"
     * is one uniform list and the leader can share their own RGAs.
     * `created_at` is the join time.
     */
    public function up(): void
    {
        Schema::create('crew_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['crew_group_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_group_members');
    }
};
