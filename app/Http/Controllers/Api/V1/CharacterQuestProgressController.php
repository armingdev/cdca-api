<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexQuestProgressRequest;
use App\Http\Resources\CharacterQuestProgressResource;
use App\Models\Character;
use App\Models\CharacterQuestProgress;
use App\Models\Rga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The per-character record of which quests the game has already settled, and
 * the means to throw it away.
 *
 * Clearing matters because one of the two verdicts is an inference: the giver
 * is equally silent about a quest that is finished and one whose prerequisites
 * are not met yet, so a character who has since levelled needs a way to make
 * the runs look again.
 */
class CharacterQuestProgressController extends Controller
{
    public function index(IndexQuestProgressRequest $request, Character $character): AnonymousResourceCollection
    {
        Gate::authorize('view', $character);

        $progress = CharacterQuestProgress::query()
            ->where('character_id', $character->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->validated('status')))
            ->with('quest:id,game_quest_id,name,giver')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return CharacterQuestProgressResource::collection($progress);
    }

    public function destroy(IndexQuestProgressRequest $request, Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $deleted = CharacterQuestProgress::query()
            ->where('character_id', $character->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->validated('status')))
            ->delete();

        return response()->json([
            'message' => "Cleared quest progress for {$character->name}.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Clear every character on the account at once — the "start the whole RGA
     * over" button.
     */
    public function destroyForRga(IndexQuestProgressRequest $request, Rga $rga): JsonResponse
    {
        Gate::authorize('update', $rga);

        $deleted = CharacterQuestProgress::query()
            ->whereIn('character_id', $rga->characters()->select('id'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->validated('status')))
            ->delete();

        return response()->json([
            'message' => "Cleared quest progress for every character on {$rga->username}.",
            'deleted' => $deleted,
        ]);
    }
}
