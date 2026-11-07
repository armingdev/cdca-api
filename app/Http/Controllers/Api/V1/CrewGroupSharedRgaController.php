<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCrewGroupSharedRgasRequest;
use App\Http\Resources\CrewGroupSharedRgaResource;
use App\Models\CrewGroup;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A member's opt-in: exactly which of their own RGAs the leader may read.
 */
class CrewGroupSharedRgaController extends Controller
{
    public function update(UpdateCrewGroupSharedRgasRequest $request, CrewGroup $crewGroup): AnonymousResourceCollection
    {
        Gate::authorize('share', $crewGroup);

        $crewGroup->replaceSharedRgas($request->user(), $request->rgaIds());

        return CrewGroupSharedRgaResource::collection(
            $crewGroup->sharedRgas()
                ->wherePivot('shared_by_user_id', $request->user()->id)
                ->orderBy('rgas.username')
                ->get()
        );
    }
}
