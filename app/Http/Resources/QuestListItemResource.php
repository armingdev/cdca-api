<?php

namespace App\Http\Resources;

use App\Models\QuestListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuestListItem
 */
class QuestListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'quest_id' => $this->quest_id,
            'label' => $this->label,
            'quest' => $this->whenLoaded('quest', fn () => [
                'id' => $this->quest->id,
                'game_quest_id' => $this->quest->game_quest_id,
                'name' => $this->quest->name,
                'giver' => $this->quest->giver,
                'required_level' => $this->quest->required_level,
                'total_exp' => $this->quest->total_exp,
            ]),
            'display_name' => $this->whenLoaded('quest', fn () => $this->displayName(), $this->label),
        ];
    }
}
