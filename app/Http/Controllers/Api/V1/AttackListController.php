<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttackListRequest;
use App\Http\Resources\AttackListResource;
use App\Models\AttackList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttackListController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AttackListResource::collection(
            $request->user()->attackLists()->withCount('targets')->latest()->get()
        );
    }

    public function store(StoreAttackListRequest $request): JsonResponse
    {
        $list = $request->user()->attackLists()->create($request->validated());

        return AttackListResource::make($list)->response()->setStatusCode(201);
    }

    public function show(AttackList $attackList): AttackListResource
    {
        Gate::authorize('view', $attackList);

        return AttackListResource::make($attackList->load('targets'));
    }

    public function destroy(AttackList $attackList): JsonResponse
    {
        Gate::authorize('delete', $attackList);

        $attackList->delete();

        return response()->json(['message' => 'Attack list deleted.']);
    }
}
