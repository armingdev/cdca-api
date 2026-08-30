<?php

namespace Database\Factories;

use App\Game\Enums\QuestProgressStatus;
use App\Models\Character;
use App\Models\CharacterQuestProgress;
use App\Models\Quest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharacterQuestProgress>
 */
class CharacterQuestProgressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'character_id' => Character::factory(),
            'quest_id' => Quest::factory(),
            'status' => QuestProgressStatus::Completed,
            'run_id' => null,
            'recorded_at' => now(),
            'context' => null,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => ['status' => QuestProgressStatus::Unavailable]);
    }
}
