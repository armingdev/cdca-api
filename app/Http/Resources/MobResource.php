<?php

namespace App\Http\Resources;

use App\Models\Mob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Mob
 */
class MobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_mob_id' => $this->game_mob_id,
            'name' => $this->name,
            'level' => $this->level,
            'rage_cost' => $this->rage_cost,
            'type' => $this->type,
            'can_form' => $this->can_form,
            'room_ids' => $this->when($this->relationLoaded('rooms'), fn () => $this->rooms->pluck('id')),
        ];
    }
}
