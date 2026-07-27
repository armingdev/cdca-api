<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Quest\QuestLocationResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexQuestsRequest;
use App\Http\Resources\QuestDetailResource;
use App\Http\Resources\QuestResource;
use App\Models\Quest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only quest catalog (crawled from show_quest.php): the finder list,
 * the rich detail page, and the giver facet for filter dropdowns.
 */
class QuestController extends Controller
{
    public function index(IndexQuestsRequest $request): AnonymousResourceCollection
    {
        $search = $request->string('search')->toString();

        $quests = Quest::query()
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('giver', 'ilike', $term));
            })
            ->when($request->filled('giver'), fn ($query) => $query->where('giver', $request->string('giver')->toString()))
            ->when($request->filled('min_level'), fn ($query) => $query->where('required_level', '>=', $request->integer('min_level')))
            ->when($request->filled('max_level'), fn ($query) => $query->where('required_level', '<=', $request->integer('max_level')))
            ->orderBy($request->string('sort', 'name')->toString(), $request->string('dir', 'asc')->toString())
            ->orderBy('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return QuestResource::collection($quests);
    }

    public function show(Quest $quest, QuestLocationResolver $resolver): QuestDetailResource
    {
        $quest->load(['steps.conditions', 'prerequisiteQuest']);

        return QuestDetailResource::make($quest)->withLocations($resolver->resolve($quest));
    }

    public function givers(): JsonResponse
    {
        return response()->json([
            'data' => Quest::query()
                ->whereNotNull('giver')
                ->select('giver')
                ->selectRaw('count(*) as quests_count')
                ->groupBy('giver')
                ->orderBy('giver')
                ->get(),
        ]);
    }
}
