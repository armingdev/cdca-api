<?php

namespace App\Http\Resources;

use App\Models\Run;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Run
 */
class RunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'config' => $this->config,
            'cast_on_start' => $this->cast_on_start,
            'require_circumspect' => $this->require_circumspect,
            'restart_every_minutes' => $this->restart_every_minutes,
            'start_at' => $this->start_at,
            'last_started_at' => $this->last_started_at,
            'participants' => RunParticipantResource::collection($this->whenLoaded('participants')),
            'created_at' => $this->created_at,
        ];
    }
}
