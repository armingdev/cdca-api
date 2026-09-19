<?php

namespace App\Game\Combat;

use App\Models\BattleEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * How many of each item a set of battles dropped — the one aggregate behind
 * the fleet-wide drops view and a run's own totals, so both always agree on
 * what counts as a drop.
 */
class DropTotals
{
    /**
     * @param  Builder<BattleEvent>  $battles  already scoped to the battles the caller may see
     * @return Collection<int, stdClass> rows of {drop_name, count}, most dropped first
     */
    public function byDrop(Builder $battles): Collection
    {
        return $battles
            ->whereNotNull('drop_name')
            ->groupBy('drop_name')
            ->select('drop_name', DB::raw('count(*) as count'))
            ->orderByDesc('count')
            ->orderBy('drop_name')
            ->toBase()
            ->get();
    }

    /**
     * The same totals split by the mob that dropped each item — "how many
     * potions came from Amdir Harvesters" — mob-less drops (PvP) grouped apart.
     *
     * @param  Builder<BattleEvent>  $battles
     * @return Collection<int, stdClass> rows of {drop_name, mob_id, mob_name, count}, most dropped first
     */
    public function byDropAndMob(Builder $battles): Collection
    {
        return $battles
            ->whereNotNull('drop_name')
            ->leftJoin('mobs', 'mobs.id', '=', 'battle_events.mob_id')
            ->groupBy('drop_name', 'battle_events.mob_id', 'mobs.name')
            ->select('drop_name', 'battle_events.mob_id', 'mobs.name as mob_name', DB::raw('count(*) as count'))
            ->orderByDesc('count')
            ->orderBy('drop_name')
            ->orderBy('mobs.name')
            ->toBase()
            ->get();
    }
}
