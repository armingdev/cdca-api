<?php

namespace App\Http\Resources;

use App\Models\Crew;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Crew
 */
class CrewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'server' => config("outwar.servers.{$this->server_id}.name"),
            // What a crew-members run takes in crew_game_ids.
            'game_crew_id' => $this->game_crew_id,
            'name' => $this->name,
            'leader' => $this->leader,
            'total_members' => $this->total_members,
            'average_level' => $this->average_level,
            'members_synced_at' => $this->members_synced_at,
        ];
    }
}
