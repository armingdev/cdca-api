<?php

namespace App\Http\Resources;

use App\Models\CrewGroupSessionRead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CrewGroupSessionRead
 */
class CrewGroupSessionReadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reader' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'rga_ids' => $this->rga_ids,
            'verified' => $this->verified,
            'read_at' => $this->read_at,
        ];
    }
}
