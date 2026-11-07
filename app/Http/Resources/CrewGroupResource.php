<?php

namespace App\Http\Resources;

use App\Models\CrewGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A crew group as one of its members sees it. Only the leader gets the
 * invite code; `shared_rgas` is whatever the controller loaded for the
 * viewer (every share for the leader, only their own for a member).
 *
 * @mixin CrewGroup
 */
class CrewGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isLeader = $this->isLedBy($request->user());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'leader' => $this->whenLoaded('leader', fn (): array => [
                'id' => $this->leader->id,
                'name' => $this->leader->name,
            ]),
            'is_leader' => $isLeader,
            'invite_code' => $this->when($isLeader, $this->invite_code),
            'members_count' => $this->whenCounted('members'),
            'members' => CrewGroupMemberResource::collection($this->whenLoaded('members')),
            'shared_rgas' => CrewGroupSharedRgaResource::collection($this->whenLoaded('sharedRgas')),
            'created_at' => $this->created_at,
        ];
    }
}
