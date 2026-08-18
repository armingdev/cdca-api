<?php

namespace App\Game\World;

use App\Game\Enums\TeleportKind;
use App\Models\TeleportAnchor;
use Closure;

/**
 * Picks the cheapest way to reach a room: walk the whole way, or jump to an
 * anchor and walk from there. Item jumps are free and uncapped, so they are
 * worth taking whenever they shorten the walk at all; the Teleport skill burns
 * 100 rage and a 60-minute cooldown, so it only wins when it saves a lot of
 * walking (`skillSavingsThreshold`).
 *
 * The graph itself stays a pure walk graph — teleports are edges layered on
 * top, because which ones exist depends on the character.
 */
class TeleportPlanner
{
    public function __construct(
        private readonly RoomGraph $graph,
        private readonly int $skillSavingsThreshold = 50,
    ) {}

    /**
     * @param  list<TeleportAnchor>  $anchors  anchors the character can use now
     *                                         (available, known destination)
     * @param  int|null  $homeTavernRoomId  free world.php?teleport=1 destination
     */
    public function plan(int $from, int $to, array $anchors, ?int $homeTavernRoomId = null): ?TeleportPlan
    {
        return $this->planToNearest($from, fn (int $roomId): bool => $roomId === $to, $anchors, $homeTavernRoomId);
    }

    /**
     * The cheapest way to reach *any* room matching the predicate — what the
     * runners need, since a farm target or quest-giver usually lives in
     * several rooms. Same rules as plan().
     *
     * @param  Closure(int): bool  $predicate
     * @param  list<TeleportAnchor>  $anchors
     */
    public function planToNearest(int $from, Closure $predicate, array $anchors, ?int $homeTavernRoomId = null): ?TeleportPlan
    {
        $best = null;
        $walk = $this->graph->pathToNearest($from, $predicate);

        if ($walk !== null) {
            $best = new TeleportPlan(anchor: null, walkPath: $walk);
        }

        if ($homeTavernRoomId !== null) {
            $best = $this->better($best, $this->viaRoom($homeTavernRoomId, $predicate, null, true));
        }

        foreach ($anchors as $anchor) {
            if (! $anchor->hasKnownDestination()) {
                continue;
            }

            $candidate = $this->viaRoom($anchor->room_id, $predicate, $anchor, false);

            if ($candidate === null) {
                continue;
            }

            if ($anchor->kind === TeleportKind::Skill && ! $this->skillIsWorthIt($best, $candidate)) {
                continue;
            }

            $best = $this->better($best, $candidate);
        }

        return $best;
    }

    /**
     * @param  Closure(int): bool  $predicate
     */
    private function viaRoom(int $landing, Closure $predicate, ?TeleportAnchor $anchor, bool $useHomeTavern): ?TeleportPlan
    {
        $path = $this->graph->pathToNearest($landing, $predicate);

        if ($path === null) {
            return null;
        }

        return new TeleportPlan(anchor: $anchor, walkPath: $path, useHomeTavern: $useHomeTavern);
    }

    /**
     * A skill jump has to beat the incumbent by a wide margin — it is the only
     * teleport with a real price, and spending the cooldown on a small saving
     * means it is unavailable when a big one comes along.
     */
    private function skillIsWorthIt(?TeleportPlan $best, TeleportPlan $candidate): bool
    {
        if ($best === null) {
            return true;
        }

        return $best->cost() - $candidate->cost() >= $this->skillSavingsThreshold;
    }

    private function better(?TeleportPlan $best, ?TeleportPlan $candidate): ?TeleportPlan
    {
        if ($candidate === null) {
            return $best;
        }

        if ($best === null) {
            return $candidate;
        }

        return $this->rank($candidate) < $this->rank($best) ? $candidate : $best;
    }

    /**
     * Cost first, then price: on a tie always take the jump that costs no rage
     * and burns no cooldown.
     *
     * @return array{int, int}
     */
    private function rank(TeleportPlan $plan): array
    {
        $costsResources = $plan->anchor !== null && ! $plan->anchor->isFree();

        return [$plan->cost(), $costsResources ? 1 : 0];
    }
}
