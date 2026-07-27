<?php

namespace App\Http\Resources;

use App\Models\Quest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim quest row for the finder list — single-table fields only.
 *
 * @mixin Quest
 */
class QuestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_quest_id' => $this->game_quest_id,
            'name' => $this->name,
            'giver' => $this->giver,
            'required_level' => $this->required_level,
            'steps_count' => $this->steps_count,
            'total_exp' => $this->total_exp,
            'item_rewards' => $this->item_rewards ?? [],
            'prerequisite' => $this->prerequisite,
            'prerequisite_quest_id' => $this->prerequisite_quest_id,
            'last_mapped_at' => $this->last_mapped_at,
        ];
    }
}
