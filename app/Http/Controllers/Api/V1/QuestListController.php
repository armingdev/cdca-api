<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestListRequest;
use App\Http\Resources\QuestListResource;
use App\Models\QuestList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class QuestListController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return QuestListResource::collection(
            $request->user()->questLists()->withCount('items')->latest()->get()
        );
    }

    public function store(StoreQuestListRequest $request): JsonResponse
    {
        $list = $request->user()->questLists()->create($request->validated());

        return QuestListResource::make($list)->response()->setStatusCode(201);
    }

    public function show(QuestList $questList): QuestListResource
    {
        Gate::authorize('view', $questList);

        return QuestListResource::make($questList->load('items'));
    }

    public function destroy(QuestList $questList): JsonResponse
    {
        Gate::authorize('delete', $questList);

        $questList->delete();

        return response()->json(['message' => 'Quest list deleted.']);
    }
}
