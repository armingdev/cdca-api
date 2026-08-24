<?php

namespace App\Http\Resources;

use App\Models\AttackList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttackList
 */
class AttackListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'server_id' => $this->server_id,
            'targets_count' => $this->whenCounted('targets'),
            'targets' => AttackListTargetResource::collection($this->whenLoaded('targets')),
            'created_at' => $this->created_at,
        ];
    }
}
