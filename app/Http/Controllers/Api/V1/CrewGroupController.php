<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCrewGroupRequest;
use App\Http\Requests\UpdateCrewGroupRequest;
use App\Http\Resources\CrewGroupResource;
use App\Models\CrewGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * cdca crew groups (crew leader mode): the groups a user leads or joined.
 */
class CrewGroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CrewGroupResource::collection(
            $request->user()->crewGroups()
                ->with('leader:id,name')
                ->withCount('members')
                ->orderBy('crew_groups.name')
                ->orderBy('crew_groups.id')
                ->get()
        );
    }

    public function store(StoreCrewGroupRequest $request): JsonResponse
    {
        $group = CrewGroup::found($request->user(), $request->validated('name'));

        return CrewGroupResource::make($group->load('leader:id,name')->loadCount('members'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Members and shares. The leader sees every shared RGA; a member sees
     * only the ones they shared themselves.
     */
    public function show(Request $request, CrewGroup $crewGroup): CrewGroupResource
    {
        Gate::authorize('view', $crewGroup);

        $user = $request->user();

        $crewGroup->load([
            'leader:id,name',
            'members' => fn ($query) => $query
                ->withCount(['sharedCrewRgas' => fn ($shares) => $shares->where('crew_group_rgas.crew_group_id', $crewGroup->id)])
                ->orderBy('users.name')
                ->orderBy('users.id'),
            'sharedRgas' => fn ($query) => $query
                ->when(! $crewGroup->isLedBy($user), fn ($mine) => $mine->where('rgas.user_id', $user->id))
                ->orderBy('rgas.username'),
        ])->loadCount('members');

        return CrewGroupResource::make($crewGroup);
    }

    public function update(UpdateCrewGroupRequest $request, CrewGroup $crewGroup): CrewGroupResource
    {
        Gate::authorize('update', $crewGroup);

        $crewGroup->update($request->validated());

        return CrewGroupResource::make($crewGroup->load('leader:id,name')->loadCount('members'));
    }

    /**
     * Disband: every membership and share goes with it (cascade).
     */
    public function destroy(CrewGroup $crewGroup): JsonResponse
    {
        Gate::authorize('delete', $crewGroup);

        $crewGroup->delete();

        return response()->json(['message' => 'Crew group disbanded.']);
    }
}
