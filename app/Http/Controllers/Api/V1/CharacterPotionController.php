<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Items\PotionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterPotionResource;
use App\Models\Character;
use App\Models\CharacterPotion;
use App\Models\Potion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A character's potions: what it holds, what is active, and drinking one.
 */
class CharacterPotionController extends Controller
{
    /**
     * The stored reading — no game request. Potions the character has run
     * out of stay listed at quantity 0 until it holds them again.
     */
    public function index(Character $character): AnonymousResourceCollection
    {
        Gate::authorize('view', $character);

        return CharacterPotionResource::collection($this->stacksOf($character));
    }

    /**
     * Read the potion tab and the active effects from the game now.
     */
    public function sync(Character $character): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('update', $character);

        if (! $character->loadMissing('rga')->rga->hasSession()) {
            return response()->json(['message' => 'No active session — log the RGA in first.'], 422);
        }

        PotionService::forCharacter($character)->sync();

        return CharacterPotionResource::collection($this->stacksOf($character));
    }

    /**
     * Drink one now — any kind, including instant ones a run never uses.
     * `200` when one was consumed; otherwise `422` with the reason
     * (`already_active`, `out_of_stock`, `refused`) and the stack as it stands.
     */
    public function activate(Character $character, Potion $potion): JsonResponse
    {
        Gate::authorize('update', $character);

        if (! $character->loadMissing('rga')->rga->hasSession()) {
            return response()->json(['message' => 'No active session — log the RGA in first.'], 422);
        }

        $result = PotionService::forCharacter($character)->activate($potion);

        return response()->json([
            'outcome' => $result->outcome,
            'message' => $result->message,
            'data' => $result->stack === null ? null : CharacterPotionResource::make($result->stack->load('potion')),
        ], $result->drank() ? 200 : 422);
    }

    /**
     * @return Collection<int, CharacterPotion>
     */
    private function stacksOf(Character $character): Collection
    {
        return CharacterPotion::with('potion')
            ->where('character_id', $character->id)
            ->join('potions', 'potions.id', '=', 'character_potions.potion_id')
            ->orderBy('potions.name')
            ->orderBy('character_potions.id')
            ->select('character_potions.*')
            ->get();
    }
}
