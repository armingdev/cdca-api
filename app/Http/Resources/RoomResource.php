<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Room
 */
class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'exits' => [
                'north' => $this->north,
                'east' => $this->east,
                'south' => $this->south,
                'west' => $this->west,
            ],
            'is_gated' => $this->is_gated,
            'gate_reason' => $this->gate_reason,
            'last_verified_at' => $this->last_verified_at,
            'mobs' => MobResource::collection($this->whenLoaded('mobs')),
        ];
    }
}
