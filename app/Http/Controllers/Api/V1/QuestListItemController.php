<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestListItemRequest;
use App\Http\Resources\QuestListResource;
use App\Models\QuestList;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class QuestListItemController extends Controller
{
    public function store(StoreQuestListItemRequest $request, QuestList $questList): QuestListResource
    {
        Gate::authorize('update', $questList);

        $questList->addQuest(
            questId: $request->integer('quest_id'),
            npcName: $request->string('npc_name')->toString(),
            label: $request->input('label'),
        );

        return QuestListResource::make($questList->fresh()->load('items'));
    }

    public function destroy(QuestList $questList, int $position): JsonResponse|QuestListResource
    {
        Gate::authorize('update', $questList);

        if (! $questList->removePosition($position)) {
            return response()->json(['message' => "No item at position {$position}."], 404);
        }

        return QuestListResource::make($questList->fresh()->load('items'));
    }
}
