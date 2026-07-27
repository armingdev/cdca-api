<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Combat\StatsService;
use App\Game\Exceptions\GameException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterResource;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CharacterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $characters = Character::query()
            ->whereHas('rga', fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($request->integer('server_id'), fn ($query, $server) => $query->where('server_id', $server))
            ->when($request->integer('rga_id'), fn ($query, $rga) => $query->where('rga_id', $rga))
            ->orderByDesc('level')
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

        if (! $character->rga->hasSession()) {
            return response()->json(['message' => 'No active session — log the RGA in first.'], 422);
        }

        try {
            StatsService::forCharacter($character)->refresh();
        } catch (GameException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return CharacterResource::make($character->fresh());
    }
}
