<?php

namespace App\Http\Resources;

use App\Models\Potion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Potion
 */
class PotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_item_id' => $this->game_item_id,
            'name' => $this->name,
            'image' => $this->image,
            'level' => $this->level,
            'player_bound' => $this->player_bound,
            'description' => $this->description,
            // buff: a run can keep it active; rage: a run can drink it for rage; other: manual only.
            'kind' => $this->kind,
            'duration_minutes' => $this->duration_minutes,
            'rage_amount' => $this->rage_amount,
        ];
    }
}
