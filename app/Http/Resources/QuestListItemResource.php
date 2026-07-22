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
            'npc_name' => $this->npc_name,
            'label' => $this->label,
            'display_name' => $this->displayName(),
        ];
    }
}
