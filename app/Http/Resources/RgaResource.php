<?php

namespace App\Http\Resources;

use App\Models\Rga;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rga
 */
class RgaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'status' => $this->status,
            'has_session' => $this->hasSession(),
            'characters_count' => $this->whenCounted('characters'),
            'characters' => CharacterResource::collection($this->whenLoaded('characters')),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
