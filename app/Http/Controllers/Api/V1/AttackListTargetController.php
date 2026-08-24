<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttackListTargetRequest;
use App\Http\Resources\AttackListResource;
use App\Models\AttackList;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AttackListTargetController extends Controller
{
    public function store(StoreAttackListTargetRequest $request, AttackList $attackList): AttackListResource
    {
        Gate::authorize('update', $attackList);

        $attackList->addTarget(
            name: $request->string('name')->toString(),
            playerId: $request->filled('player_id') ? $request->integer('player_id') : null,
        );

        return AttackListResource::make($attackList->fresh()->load('targets'));
    }

    public function destroy(AttackList $attackList, int $position): JsonResponse|AttackListResource
    {
        Gate::authorize('update', $attackList);

        if (! $attackList->removePosition($position)) {
            return response()->json(['message' => "No target at position {$position}."], 404);
        }

        return AttackListResource::make($attackList->fresh()->load('targets'));
    }
}
