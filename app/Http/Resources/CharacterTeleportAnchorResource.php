<?php

namespace App\Http\Resources;

use App\Models\CharacterTeleportAnchor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CharacterTeleportAnchor
 */
class CharacterTeleportAnchorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'anchor_id' => $this->teleport_anchor_id,
            'name' => $this->anchor->name,
            'kind' => $this->anchor->kind->value,
            'game_item_id' => $this->anchor->game_item_id,
            'room_id' => $this->anchor->room_id,
            'room_name' => $this->anchor->room?->name,
            'description' => $this->anchor->description,
            'required_level' => $this->anchor->required_level,
            'rage_cost' => $this->anchor->rage_cost,
            'cooldown_minutes' => $this->anchor->cooldown_minutes,
            'free' => $this->anchor->isFree(),
            'destination_known' => $this->anchor->hasKnownDestination(),
            'available' => $this->is_available,
            'last_used_at' => $this->last_used_at,
            'synced_at' => $this->synced_at,
        ];
    }
}
