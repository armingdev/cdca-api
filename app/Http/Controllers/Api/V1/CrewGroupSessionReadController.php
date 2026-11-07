<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCrewGroupSessionReadsRequest;
use App\Http\Resources\CrewGroupSessionReadResource;
use App\Models\CrewGroup;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The audit trail: every leader read of this group's shared sessions,
 * newest first. Visible to every member, so sharing is never blind.
 */
class CrewGroupSessionReadController extends Controller
{
    public function index(IndexCrewGroupSessionReadsRequest $request, CrewGroup $crewGroup): AnonymousResourceCollection
    {
        Gate::authorize('view', $crewGroup);

        return CrewGroupSessionReadResource::collection(
            $crewGroup->sessionReads()
                ->with('user:id,name')
                ->orderByDesc('read_at')
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 25))
        );
    }
}
