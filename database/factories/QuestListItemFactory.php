<?php

namespace Database\Factories;

use App\Models\Quest;
use App\Models\QuestList;
use App\Models\QuestListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestListItem>
 */
class QuestListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quest_list_id' => QuestList::factory(),
            'position' => 1,
            'quest_id' => Quest::factory(),
            'label' => null,
        ];
    }
}
