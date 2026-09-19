<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Combat\StatsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCharactersRequest;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CharacterController extends Controller
{
    /**
     * The whole fleet in one response (no pagination — the grid sorts and
     * filters client-side). The order is total on purpose: Postgres returns
     * tied rows in heap order, and every status or stats write moves a row,
     * so ordering by level alone reshuffled same-level characters on each poll.
     */
    public function index(IndexCharactersRequest $request): AnonymousResourceCollection
    {
        $characters = Character::query()
            ->whereHas('rga', fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($request->integer('server_id'), fn ($query, $server) => $query->where('server_id', $server))
            ->when($request->integer('rga_id'), fn ($query, $rga) => $query->where('rga_id', $rga))
            ->when($request->validated('ownership'), fn ($query, $ownership) => $query->where('is_trustee', $ownership === 'trustee'))
            ->orderByDesc('level')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return CharacterResource::collection($characters);
    }

    public function show(Character $character): CharacterResource
    {
        Gate::authorize('view', $character);

        return CharacterResource::make($character);
    }

    /**
     * Read fresh rage/exp/level from the game right now and return the
     * updated character (synchronous — one userstats.php request).
     */
    public function refreshStats(Character $character): CharacterResource|JsonResponse
    {
        Gate::authorize('update', $character);

        if (! $character->loadMissing('rga')->rga->hasSession()) {
            return response()->json(['message' => 'No active session — log the RGA in first.'], 422);
        }

        StatsService::forCharacter($character)->refresh();

        return CharacterResource::make($character->fresh());
    }
}
