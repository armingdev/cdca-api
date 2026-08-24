<?php

namespace App\Http\Resources;

use App\Models\AttackListTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttackListTarget
 */
class AttackListTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'name' => $this->name,
            // Filled in once a search resolves the name; null until then.
            'player_id' => $this->player_id,
        ];
    }
}
