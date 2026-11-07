<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A crew-group member: name only — other members never see an email.
 *
 * @mixin User
 */
class CrewGroupMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Loaded through CrewGroup::members(): the membership row is the pivot.
        $membership = $this->resource->relationLoaded('pivot') ? $this->resource->getRelation('pivot') : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'joined_at' => $membership?->created_at,
            'shared_rgas_count' => $this->whenCounted('sharedCrewRgas'),
        ];
    }
}
