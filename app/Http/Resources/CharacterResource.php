<?php

namespace App\Http\Resources;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Character
 */
class CharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rga_id' => $this->rga_id,
            'suid' => $this->suid,
            'server_id' => $this->server_id,
            'server' => config("outwar.servers.{$this->server_id}.name"),
            'name' => $this->name,
            'level' => $this->level,
            'rage' => $this->rage,
            'exp' => $this->exp,
            'skill_points' => $this->skill_points,
            'school' => $this->school,
            'crew' => $this->crew,
            'current_room_id' => $this->current_room_id,
            'status' => $this->status,
            'last_stats_at' => $this->last_stats_at,
        ];
    }
}
