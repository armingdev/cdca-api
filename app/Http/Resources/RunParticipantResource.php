<?php

namespace App\Http\Resources;

use App\Models\RunParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RunParticipant
 */
class RunParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'character_id' => $this->character_id,
            'character' => new CharacterResource($this->whenLoaded('character')),
            'status' => $this->status,
            'wins' => $this->wins,
            'losses' => $this->losses,
            'errors' => $this->errors,
            'last_activity' => $this->last_activity,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
