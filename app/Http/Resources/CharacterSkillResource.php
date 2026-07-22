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
            'cast_on_start' => $this->cast_on_start,
            'last_cast_at' => $this->last_cast_at,
            'buff_active' => $this->when($this->relationLoaded('skill'), fn () => $this->isBuffActive()),
            'on_cooldown' => $this->when($this->relationLoaded('skill'), fn () => $this->isOnCooldown()),
        ];
    }
}
