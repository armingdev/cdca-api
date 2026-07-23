<?php

namespace App\Http\Resources;

use App\Models\CharacterSkill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CharacterSkill
 */
class CharacterSkillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'skill_id' => $this->skill_id,
            'skill' => new SkillResource($this->whenLoaded('skill')),
            'trained_level' => $this->trained_level,
            'bonus_level' => $this->bonus_level,
            'effective_level' => $this->effectiveLevel(),
            'castable' => $this->isCastable(),
            'current_rage_cost' => $this->current_rage_cost,
            'current_cooldown_minutes' => $this->current_cooldown_minutes,
            'current_duration_minutes' => $this->current_duration_minutes,
            'cast_on_start' => $this->cast_on_start,
            'last_cast_at' => $this->last_cast_at,
            'recharge_until' => $this->recharge_until,
            'buff_until' => $this->buff_until,
            'synced_at' => $this->synced_at,
            'buff_active' => $this->when($this->relationLoaded('skill'), fn () => $this->isBuffActive()),
            'on_cooldown' => $this->when($this->relationLoaded('skill'), fn () => $this->isOnCooldown()),
        ];
    }
}
