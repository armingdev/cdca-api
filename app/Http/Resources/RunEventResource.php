<?php

namespace App\Http\Resources;

use App\Models\RunEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RunEvent
 */
class RunEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'run_participant_id' => $this->run_participant_id,
            'character_id' => $this->character_id,
            'character' => $this->when(
                $this->relationLoaded('character') && $this->character !== null,
                fn () => $this->character?->name,
            ),
            'type' => $this->type,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'created_at' => $this->created_at,
        ];
    }
}
