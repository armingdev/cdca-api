<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Enums\BattleOutcome;
use App\Http\Controllers\Controller;
use App\Http\Resources\BattleEventResource;
use App\Models\BattleEvent;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StatsController extends Controller
{
    /**
     * Recent battle events for a character (newest first, paginated).
     */
    public function battles(Request $request, Character $character): AnonymousResourceCollection
    {
        Gate::authorize('view', $character);

        $events = $character->battleEvents()
            ->with('mob:id,name')
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 50));

        return BattleEventResource::collection($events);
    }

    /**
     * Aggregate per-mob W/L and drop counts for a character (the Stats tab).
     */
    public function summary(Character $character): JsonResponse
    {
        Gate::authorize('view', $character);

        $perMob = BattleEvent::query()
            ->where('character_id', $character->id)
            ->whereNotNull('mob_id')
            ->join('mobs', 'mobs.id', '=', 'battle_events.mob_id')
            ->groupBy('mobs.name')
            ->select('mobs.name', DB::raw('count(*) as total'))
            ->selectRaw('count(*) filter (where outcome = ?) as wins', [BattleOutcome::Win->value])
            ->selectRaw('count(*) filter (where outcome = ?) as losses', [BattleOutcome::Loss->value])
            ->orderByDesc('total')
            ->get();

        $drops = BattleEvent::query()
            ->where('character_id', $character->id)
            ->whereNotNull('drop_name')
            ->groupBy('drop_name')
            ->select('drop_name', DB::raw('count(*) as count'))
            ->orderByDesc('count')
            ->get();

        return response()->json([
            'mobs' => $perMob,
            'drops' => $drops,
        ]);
    }
}
