<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('game_quest_id')->unique();
            $table->string('name')->index();
            $table->unsignedInteger('required_level')->nullable()->index();
            $table->string('prerequisite')->nullable();
            $table->foreignId('prerequisite_quest_id')->nullable()->constrained('quests')->nullOnDelete();
            $table->string('giver')->nullable()->index();
            $table->unsignedInteger('steps_count')->default(0);
            $table->unsignedBigInteger('total_exp')->default(0)->index();
            $table->json('item_rewards')->nullable();
            $table->timestamp('last_mapped_at')->nullable();
            $table->timestamps();
        });

        // Trigram index so the quest finder's contains-search stays indexed.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX quests_name_trgm_index ON quests USING gin (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quests');
    }
};
