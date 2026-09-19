<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Combat\DropTotals;
use App\Game\Enums\BattleOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBattleEventsRequest;
use App\Http\Requests\IndexDropStatsRequest;
use App\Http\Resources\BattleEventResource;
use App\Models\BattleEvent;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StatsController extends Controller
{
    /**
     * Recent battle events for a character (newest first, paginated).
     */
    public function battles(IndexBattleEventsRequest $request, Character $character): AnonymousResourceCollection
    {
        Gate::authorize('view', $character);

        $events = $character->battleEvents()
            ->with('mob:id,name')
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 50));

        return BattleEventResource::collection($events);
    }

    /**
     * Drop totals across the whole fleet: "how many potions have dropped",
     * whichever runs and characters they came from. Narrowed by date range,
     * character, mob or run; `group_by=mob` splits each item by its source.
     */
    public function drops(IndexDropStatsRequest $request, DropTotals $totals): JsonResponse
    {
        $battles = BattleEvent::query()
            ->whereIn('battle_events.character_id', Character::query()
                ->select('id')
                ->whereHas('rga', fn ($query) => $query->where('user_id', $request->user()->id)))
            ->when($request->validated('from'), fn ($query, $from) => $query->where('occurred_at', '>=', Carbon::parse($from)))
            ->when($request->validated('to'), fn ($query, $to) => $query->where('occurred_at', '<=', Carbon::parse($to)))
            ->when($request->integer('character_id'), fn ($query, $id) => $query->where('battle_events.character_id', $id))
            ->when($request->integer('mob_id'), fn ($query, $id) => $query->where('battle_events.mob_id', $id))
            ->when($request->integer('run_id'), fn ($query, $id) => $query->where('battle_events.run_id', $id));

        $rows = $request->validated('group_by') === 'mob'
            ? $totals->byDropAndMob($battles)
            : $totals->byDrop($battles);

        return response()->json([
            'drops' => $rows,
            'total' => $rows->sum('count'),
        ]);
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
