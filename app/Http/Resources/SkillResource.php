<?php

namespace App\Http\Resources;

use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Skill
 */
class SkillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'school' => $this->school,
            'rage_cost' => $this->rage_cost,
            'cooldown_minutes' => $this->cooldown_minutes,
            'duration_minutes' => $this->duration_minutes,
            'description' => $this->description,
        ];
    }
}
