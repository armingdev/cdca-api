<?php

namespace App\Http\Resources;

use App\Models\Rga;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One share grant: which RGA, who shared it, when. No secrets — the
 * session itself is only served by the leader-only sessions endpoint.
 *
 * @mixin Rga
 */
class CrewGroupSharedRgaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Loaded through CrewGroup::sharedRgas(): the share grant is the pivot.
        $grant = $this->resource->relationLoaded('pivot') ? $this->resource->getRelation('pivot') : null;

        return [
            'rga_id' => $this->id,
            'username' => $this->username,
            'shared_by_user_id' => $grant?->shared_by_user_id,
            'shared_at' => $grant?->created_at,
            'has_session' => $this->hasSession(),
        ];
    }
}
