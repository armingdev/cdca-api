<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\JoinCrewGroupRequest;
use App\Http\Resources\CrewGroupResource;
use App\Models\CrewGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Joining with an invite code, leaving, and the leader removing a member.
 * Joining shares nothing — each RGA is shared separately afterwards.
 */
class CrewGroupMemberController extends Controller
{
    public function store(JoinCrewGroupRequest $request): JsonResponse
    {
        $group = CrewGroup::findByInviteCode($request->validated('invite_code'));

        if ($group === null) {
            throw ValidationException::withMessages(['invite_code' => ['No crew group has that invite code.']]);
        }

        $group->members()->syncWithoutDetaching([$request->user()->id]);

        return CrewGroupResource::make($group->load('leader:id,name')->loadCount('members'))->response();
    }

    /**
     * Leave (a member removing themselves) or kick (the leader removing a
     * member). Either way the member's shares into this group are withdrawn.
     */
    public function destroy(CrewGroup $crewGroup, User $user): JsonResponse
    {
        Gate::authorize('removeMember', [$crewGroup, $user]);

        if (! $crewGroup->hasMember($user)) {
            return response()->json(['message' => 'That user is not in this crew group.'], 404);
        }

        $crewGroup->removeMember($user);

        return response()->json(['message' => 'Removed from the crew group; their shared sessions are no longer readable.']);
    }
}
