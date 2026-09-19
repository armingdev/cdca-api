<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * List names were unique across every user, so importing or copying a
     * list someone else already had ("75 Caverns") was impossible. Names are
     * now unique per owner, like attack lists. Built-in lists (no owner) keep
     * their own uniqueness through a partial index, because a plain composite
     * unique treats every NULL owner as distinct.
     *
     * is_public is what puts a user's list in front of other users.
     */
    public function up(): void
    {
        Schema::table('quest_lists', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['user_id', 'name']);
            $table->boolean('is_public')->default(false)->after('name');
            $table->index('is_public');
        });

        DB::statement('create unique index quest_lists_builtin_name_unique on quest_lists (name) where user_id is null');
    }

    public function down(): void
    {
        DB::statement('drop index quest_lists_builtin_name_unique');

        Schema::table('quest_lists', function (Blueprint $table) {
            $table->dropIndex(['is_public']);
            $table->dropColumn('is_public');
            $table->dropUnique(['user_id', 'name']);
            $table->unique('name');
        });
    }
};
