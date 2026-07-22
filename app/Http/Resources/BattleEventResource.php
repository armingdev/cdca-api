<?php

namespace App\Http\Resources;

use App\Models\BattleEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BattleEvent
 */
class BattleEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'character_id' => $this->character_id,
            'kind' => $this->kind,
            'outcome' => $this->outcome,
            'mob_id' => $this->mob_id,
            'mob' => $this->when($this->relationLoaded('mob') && $this->mob !== null, fn () => $this->mob?->name),
            'opponent_name' => $this->opponent_name,
            'room_id' => $this->room_id,
            'battle_id' => $this->battle_id,
            'exp_gained' => $this->exp_gained,
            'gold_gained' => $this->gold_gained,
            'drop_name' => $this->drop_name,
            'fail_reason' => $this->fail_reason,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
