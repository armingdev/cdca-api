<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CrewGroupResource;
use App\Models\CrewGroup;
use Illuminate\Support\Facades\Gate;

/**
 * Issue a new invite code — the old one stops working at once. Existing
 * members are unaffected.
 */
class CrewGroupInviteCodeController extends Controller
{
    public function store(CrewGroup $crewGroup): CrewGroupResource
    {
        Gate::authorize('update', $crewGroup);

        $crewGroup->rotateInviteCode();

        return CrewGroupResource::make($crewGroup->load('leader:id,name')->loadCount('members'));
    }
}
