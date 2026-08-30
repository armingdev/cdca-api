<?php

namespace App\Http\Resources;

use App\Models\CharacterQuestProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CharacterQuestProgress
 */
class CharacterQuestProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'character_id' => $this->character_id,
            'quest_id' => $this->quest_id,
            'quest' => $this->whenLoaded('quest', fn () => [
                'game_quest_id' => $this->quest->game_quest_id,
                'name' => $this->quest->name,
                'giver' => $this->quest->giver,
            ]),
            'status' => $this->status,
            'run_id' => $this->run_id,
            'recorded_at' => $this->recorded_at,
            'context' => $this->context,
        ];
    }
}
