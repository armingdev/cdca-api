<?php

namespace App\Http\Resources;

use App\Models\CharacterPotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CharacterPotion
 */
class CharacterPotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'potion_id' => $this->potion_id,
            'potion' => PotionResource::make($this->whenLoaded('potion')),
            'quantity' => $this->quantity,
            'active' => $this->isActive(),
            // The game's time left as of active_synced_at (or an activation's
            // estimate until the next read). Null = not active.
            'active_until' => $this->active_until,
            'active_synced_at' => $this->active_synced_at,
            'last_activated_at' => $this->last_activated_at,
            'refused_until' => $this->refused_until,
            'synced_at' => $this->synced_at,
        ];
    }
}
