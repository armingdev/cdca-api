<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Quest\QuestListImporter;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportQuestListRequest;
use App\Http\Requests\IndexQuestListsRequest;
use App\Http\Requests\StoreQuestListRequest;
use App\Http\Requests\UpdateQuestListRequest;
use App\Http\Resources\QuestListResource;
use App\Models\QuestList;
use App\Models\QuestListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class QuestListController extends Controller
{
    public function index(IndexQuestListsRequest $request): AnonymousResourceCollection
    {
        $lists = $request->validated('scope') === 'community'
            ? QuestList::query()->community($request->user())->with('user:id,name')
            : $request->user()->questLists();

        return QuestListResource::collection(
            $lists->withCount('items')->latest()->latest('id')->get()
        );
    }

    public function store(StoreQuestListRequest $request): JsonResponse
    {
        $list = $request->user()->questLists()->create($request->validated());

        return QuestListResource::make($list)->response()->setStatusCode(201);
    }

    /**
     * Build a list from a dDCT quest-list file or pasted text. Lines that
     * match nothing in the catalog come back in `import.unmatched` instead of
     * failing the whole import.
     */
    public function import(ImportQuestListRequest $request, QuestListImporter $importer): JsonResponse
    {
        $result = $importer->import(
            $request->user(),
            $request->content(),
            $request->validated('name'),
            $request->fallbackName(),
        );

        return QuestListResource::make($result->questList->load('items.quest'))
            ->additional(['import' => [
                'imported' => $result->questList->items->count(),
                'unmatched' => $result->unmatched,
                'ambiguous' => $result->ambiguous,
            ]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Copy a list — one's own, a published one, or a built-in one — into the
     * user's own lists, where it can be edited and run.
     */
    public function copy(QuestList $questList, Request $request): JsonResponse
    {
        Gate::authorize('copy', $questList);

        $copy = DB::transaction(function () use ($questList, $request): QuestList {
            $copy = $request->user()->questLists()->create([
                'name' => QuestList::availableNameFor($request->user(), $questList->name),
            ]);

            $copy->items()->insert($questList->items()->get()->map(fn (QuestListItem $item): array => [
                'quest_list_id' => $copy->id,
                'position' => $item->position,
                'quest_id' => $item->quest_id,
                'label' => $item->label,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            return $copy;
        });

        return QuestListResource::make($copy->load('items.quest'))->response()->setStatusCode(201);
    }

    public function update(UpdateQuestListRequest $request, QuestList $questList): QuestListResource
    {
        Gate::authorize('update', $questList);

        $questList->update($request->validated());

        return QuestListResource::make($questList->load('items.quest'));
    }

    public function show(QuestList $questList): QuestListResource
    {
        Gate::authorize('view', $questList);

        return QuestListResource::make($questList->load('items.quest'));
    }

    public function destroy(QuestList $questList): JsonResponse
    {
        Gate::authorize('delete', $questList);

        $questList->delete();

        return response()->json(['message' => 'Quest list deleted.']);
    }
}
