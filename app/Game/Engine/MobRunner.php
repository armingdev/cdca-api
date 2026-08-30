<?php

namespace App\Game\Engine;

use App\Game\Combat\AttackService;
use App\Game\Combat\StatsService;
use App\Game\Data\MobSighting;
use App\Game\Data\RoomBlob;
use App\Game\Data\UserStats;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\RunSignal;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\SessionCollisionException;
use App\Game\Items\GearManager;
use App\Game\Items\JunkDropper;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Game\World\TeleportPlanner;
use App\Game\World\TeleportService;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;
use Closure;

/**
 * The mob-mode loop for one character: pathfind to the target mobs' rooms,
 * then load room → fresh encid → attack → record → refresh stats, until a
 * stop condition fires. Used by the outwar:attack command (foreground) and
 * RunMobJob (queued) — callbacks carry logging, external control signals,
 * and per-battle hooks.
 */
class MobRunner
{
    /** Smart mode: losses in a row to one mob before we accept we cannot beat it. */
    private const int MAX_CONSECUTIVE_LOSSES = 3;

    /** Refused attacks in a row on one mob before we stop offering it rage. */
    private const int MAX_CONSECUTIVE_ATTACK_FAILURES = 5;

    private int $wins = 0;

    private int $losses = 0;

    private int $errors = 0;

    /** Kills carried over from earlier cycles of the same run, counted against max_kills. */
    private int $winsBaseline = 0;

    private int $consecutiveLosses = 0;

    private ?string $lossStreakMob = null;

    /** @var array<string, true> mob names smart mode has given up on for this run */
    private array $outmatched = [];

    /**
     * Rooms the targets are known to spawn in that this pass actually stood
     * in. Reaching one and finding nothing alive is the respawn signal — see
     * targetsRespawnPending().
     */
    private int $spawnRoomsVisited = 0;

    /** A target rendered as a corpse in a visited room. */
    private bool $sawDeadTargets = false;

    /** @var array<string, int> mob name => consecutive failed attacks this pass */
    private array $attackFailures = [];

    /** @var array<string, true> mob names whose attacks keep failing — skipped for this pass */
    private array $unattackable = [];

    /** @var array<string, array{cost: int, held: int}> mob name => the price it refused us at */
    private array $unaffordable = [];

    public function __construct(
        private readonly MobRunConfig $config,
        private readonly Navigator $navigator,
        private readonly AttackService $attacker,
        private readonly StatsService $stats,
        private readonly ?JunkDropper $junkDropper = null,
        private readonly ?GearManager $gearManager = null,
        private readonly ?TeleportService $teleports = null,
    ) {}

    public static function forCharacter(Character $character, MobRunConfig $config): self
    {
        return new self(
            $config,
            Navigator::forCharacter($character),
            AttackService::forCharacter($character),
            StatsService::forCharacter($character),
            JunkDropper::forCharacter($character),
            GearManager::forCharacter($character),
            TeleportService::forCharacter($character),
        );
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): RunSignal|null  $signal  external control signal, polled every iteration
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs  just-in-time buff top-up, called before each attack
     * @param  int  $killsAlreadyDone  kills from earlier cycles, so max_kills spans pauses/waits
     *
     * @throws GameException when the targets have no mapped rooms
     */
    public function run(
        ?Closure $log = null,
        ?Closure $signal = null,
        ?Closure $onBattle = null,
        ?Closure $ensureBuffs = null,
        int $killsAlreadyDone = 0,
    ): MobRunSummary {
        $log ??= fn (string $message) => null;
        $this->winsBaseline = max(0, $killsAlreadyDone);

        try {
            return $this->loop($log, $signal, $onBattle, $ensureBuffs);
        } finally {
            $this->dropJunk($log);
        }
    }

    /**
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     */
    private function loop(Closure $log, ?Closure $signal, ?Closure $onBattle, ?Closure $ensureBuffs = null): MobRunSummary
    {
        $targetRooms = Mob::whereIn('name', $this->config->mobNames)
            ->with('rooms:id')
            ->get()
            ->flatMap(fn (Mob $mob) => $mob->rooms->pluck('id'))
            ->unique()
            ->values();

        if ($targetRooms->isEmpty()) {
            throw new GameException('No known rooms for the target mobs — map the area first or check the names.');
        }

        $graph = RoomGraph::fromDatabase();
        $planner = new TeleportPlanner($graph);

        // Free item anchors only: a run must never spend rage (its fuel) or the
        // Teleport skill's hour-long cooldown on travel. Read once from the DB
        // — no game requests, and an empty list simply means "walk", exactly
        // as before the character synced any anchors.
        $anchors = $this->teleports?->freeAnchors() ?? [];

        if ($anchors !== []) {
            $log(count($anchors).' teleport anchors available for routing.');
        }

        $current = $this->stats->refresh();

        if ($this->config->smart) {
            $this->optimizeGear($current->level, $log);
        }

        $blob = $this->navigator->loadCurrentRoom();
        $graph->addRoom($blob->curRoom, $blob->exits);
        $exhausted = [];

        while (true) {
            $control = $signal !== null ? $signal() : RunSignal::None;

            if ($control === RunSignal::Stop) {
                return $this->summary('Stop requested.', RunEndReason::ExternalStop);
            }

            if ($control === RunSignal::Pause) {
                return $this->summary('Pause requested.', RunEndReason::ExternalPause);
            }

            if ($control === RunSignal::CircumspectExpired) {
                return $this->summary('Circumspect expired.', RunEndReason::CircumspectExpired);
            }

            if ($current->rage < $this->config->stopRage) {
                $recovered = $this->recoverRage($log);

                if ($recovered === null || $recovered < $this->config->stopRage) {
                    return $this->summary("Rage below the {$this->config->stopRage} floor.", RunEndReason::RageExhausted);
                }

                $current = $this->stats->refresh();
            }

            if ($this->config->maxKills > 0 && $this->winsBaseline + $this->wins >= $this->config->maxKills) {
                return $this->summary("Reached {$this->config->maxKills} kills.", RunEndReason::TargetReached);
            }

            $sighting = $this->liveTarget($blob);

            if ($sighting !== null && ! $this->canAfford($sighting, $current)) {
                $current = $this->recoverRageFor($sighting, $current, $log);

                if (! $this->canAfford($sighting, $current)) {
                    $this->markUnaffordable($sighting, $current, $log);

                    // Every configured target is priced out of reach, so the
                    // rest of the sweep could only rediscover that one room at
                    // a time. Park now and let the hourly tick fix it.
                    if ($this->allTargetsUnaffordable()) {
                        return $this->summary($this->rageShortfallReason(), RunEndReason::RageInsufficient);
                    }

                    continue;
                }
            }

            if ($sighting !== null) {
                // A live, affordable target is in front of us: this is the
                // moment the buffs are worth spending, not the walk that got
                // us here. The hook is self-throttling, so calling it before
                // every attack costs nothing once the buffs are up — and it
                // re-casts whatever lapsed since the last one.
                $ensureBuffs?->__invoke();

                $event = $this->attacker->attack($sighting);
                $this->tally($event, $sighting, $log);
                $onBattle?->__invoke($event);

                $current = $this->stats->refresh();

                if ($event->outcome === BattleOutcome::Win) {
                    $this->consecutiveLosses = 0;
                    $this->lossStreakMob = null;
                }

                if ($this->trackAttackFailures($event, $sighting, $log)) {
                    return $this->summary(
                        "Every target refused the attack — last was {$sighting->name}.",
                        RunEndReason::Stuck,
                    );
                }

                if ($event->outcome === BattleOutcome::Loss && $this->config->smart) {
                    $current = $this->handleLoss($sighting, $current, $log);

                    if ($this->allTargetsOutmatched()) {
                        return $this->summary(
                            "Outmatched by {$sighting->name} — stopping to preserve rage.",
                            RunEndReason::Outmatched,
                        );
                    }
                }

                $blob = $this->navigator->loadCurrentRoom();

                continue;
            }

            if ($targetRooms->contains($blob->curRoom)) {
                $this->spawnRoomsVisited++;
            }

            if (! $this->sawDeadTargets && $this->hasDeadTarget($blob)) {
                $this->sawDeadTargets = true;
                $log('Targets here are dead — respawn pending.');
            }

            $exhausted[$blob->curRoom] = true;

            $plan = $planner->planToNearest(
                $blob->curRoom,
                fn (int $roomId): bool => $targetRooms->contains($roomId) && ! isset($exhausted[$roomId]),
                $anchors,
            );

            if ($plan === null) {
                // A cheaper target may have carried the sweep to its end while
                // a pricier one was skipped all along; that pass did not run
                // out of mobs, it ran out of rage.
                if ($this->unaffordable !== []) {
                    return $this->summary($this->rageShortfallReason(), RunEndReason::RageInsufficient);
                }

                return $this->summary('No live targets remain in any known room.', RunEndReason::Completed);
            }

            try {
                if ($plan->anchor !== null) {
                    $log("Teleporting with {$plan->anchor->name} (saves the walk).");
                }

                $blob = $this->teleports !== null
                    ? $this->teleports->travel($plan)
                    : ($plan->steps() > 0 ? $this->navigator->walk($plan->walkPath) : $this->navigator->loadCurrentRoom());

                $graph->addRoom($blob->curRoom, $blob->exits);
            } catch (GameException $exception) {
                $log($exception->getMessage());
                $this->errors++;
                // end() takes its argument by reference, and walkPath is a
                // readonly property — passing it directly is a fatal Error.
                $walkPath = $plan->walkPath;
                $exhausted[end($walkPath)] = true;
                $blob = $this->navigator->loadCurrentRoom();
            }
        }
    }

    /**
     * A configured target rendered as a corpse in this room: it exists here and
     * will respawn on the game's timer, which is what separates "cleared the
     * room" from "this mob does not spawn here". Outmatched-but-alive mobs
     * deliberately do not count — waiting cannot fix being too weak.
     */
    private function hasDeadTarget(RoomBlob $blob): bool
    {
        foreach ($blob->mobs as $sighting) {
            if ($sighting->isDead && in_array($sighting->name, $this->config->mobNames, true)) {
                return true;
            }
        }

        return false;
    }

    private function liveTarget(RoomBlob $blob): ?MobSighting
    {
        foreach ($blob->mobs as $sighting) {
            if ($sighting->isDead
                || isset($this->outmatched[$sighting->name])
                || isset($this->unattackable[$sighting->name])
                || isset($this->unaffordable[$sighting->name])
            ) {
                continue;
            }

            if (in_array($sighting->name, $this->config->mobNames, true)) {
                return $sighting;
            }
        }

        return null;
    }

    /**
     * The game prices each attack itself and refuses — with a 200 and an empty
     * body, so there is nothing to parse — when the character cannot pay. The
     * price rides in every room render, so the check is free and belongs here
     * rather than in a retry loop around a request that will never succeed.
     *
     * A zero cost means the room blob did not carry one; attacking and reading
     * the refusal is better than refusing to attack on missing data.
     */
    private function canAfford(MobSighting $sighting, UserStats $current): bool
    {
        return $sighting->rageCost <= 0 || $current->rage >= $sighting->rageCost;
    }

    /**
     * Levelling refills rage, so a run allowed to level can pay its way out of
     * a shortfall. Returns the stats to keep deciding with — unchanged when no
     * level was available.
     *
     * @param  Closure(string): void  $log
     */
    private function recoverRageFor(MobSighting $sighting, UserStats $current, Closure $log): UserStats
    {
        $recovered = $this->recoverRage($log);

        if ($recovered === null) {
            return $current;
        }

        $log("Leveled to cover {$sighting->name}'s rage cost.");

        return $this->stats->refresh();
    }

    /**
     * Remember what a target cost when we could not pay it, and stop offering
     * it rage for the rest of the pass.
     *
     * @param  Closure(string): void  $log
     */
    private function markUnaffordable(MobSighting $sighting, UserStats $current, Closure $log): void
    {
        if (isset($this->unaffordable[$sighting->name])) {
            return;
        }

        $this->unaffordable[$sighting->name] = ['cost' => $sighting->rageCost, 'held' => $current->rage];

        $log(sprintf(
            '%s costs %s rage and the character holds %s — skipping it this pass.',
            $sighting->name,
            number_format($sighting->rageCost),
            number_format($current->rage),
        ));
    }

    private function allTargetsUnaffordable(): bool
    {
        foreach ($this->config->mobNames as $name) {
            if (! isset($this->unaffordable[$name])) {
                return false;
            }
        }

        return true;
    }

    /** The cheapest target we could not pay for, named with its price. */
    private function rageShortfallReason(): string
    {
        $cheapest = collect($this->unaffordable)->sortBy('cost');

        return sprintf(
            '%s costs %s rage and the character holds %s.',
            (string) $cheapest->keys()->first(),
            number_format((int) $cheapest->first()['cost']),
            number_format((int) $cheapest->first()['held']),
        );
    }

    /** How much rage the cheapest unaffordable target was short by. */
    private function rageShortfall(): ?int
    {
        $cheapest = collect($this->unaffordable)->sortBy('cost')->first();

        return $cheapest !== null ? max(0, $cheapest['cost'] - $cheapest['held']) : null;
    }

    /**
     * A refused attack costs no rage and leaves the mob standing, so the loop
     * would otherwise re-read the room and re-attack forever — the shape of
     * every "spamming failed requests" report. A mob that refuses
     * MAX_CONSECUTIVE_ATTACK_FAILURES times in a row is dropped for the rest
     * of the pass; the streak resets the moment any attack lands.
     *
     * Returns true when nothing attackable is left, so the caller can end the
     * pass instead of wandering between rooms it can do nothing with.
     *
     * @param  Closure(string): void  $log
     */
    private function trackAttackFailures(BattleEvent $event, MobSighting $sighting, Closure $log): bool
    {
        if ($event->outcome !== BattleOutcome::Failed) {
            $this->attackFailures[$sighting->name] = 0;

            return false;
        }

        $failures = ($this->attackFailures[$sighting->name] ?? 0) + 1;
        $this->attackFailures[$sighting->name] = $failures;

        if ($failures < self::MAX_CONSECUTIVE_ATTACK_FAILURES) {
            return false;
        }

        $this->unattackable[$sighting->name] = true;
        $log("Skipping {$sighting->name} — {$failures} attacks in a row were refused.");

        foreach ($this->config->mobNames as $name) {
            if (! isset($this->unattackable[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the pass ended because the targets are on their respawn timer
     * rather than because there is no way to reach them.
     *
     * Standing in a room the world database says a target spawns in and
     * finding none of them alive *is* the respawn signal. The corpse flag is
     * kept as a corroborating fast path, but it cannot be the only evidence:
     * it depends on the game rendering a killed mob at all, which we have
     * never verified, and when it does not the whole pass reads as "stuck" and
     * a perfectly resumable quest stops for good.
     */
    private function targetsRespawnPending(): bool
    {
        return $this->sawDeadTargets || $this->spawnRoomsVisited > 0;
    }

    /**
     * Smart mode's reaction to a lost battle: level up (levels grant atk/def
     * and refill rage for free), then re-check gear — the fight may have
     * dropped something wearable, and a new level can unlock what we already
     * carry. A mob that wins MAX_CONSECUTIVE_LOSSES in a row anyway is marked
     * outmatched so we stop feeding it rage.
     *
     * Returns the stats to keep running with; a level-up changes them.
     *
     * @param  Closure(string): void  $log
     */
    private function handleLoss(MobSighting $sighting, UserStats $current, Closure $log): UserStats
    {
        if ($sighting->name !== $this->lossStreakMob) {
            $this->lossStreakMob = $sighting->name;
            $this->consecutiveLosses = 0;
        }

        $this->consecutiveLosses++;

        if ($this->stats->tryLevelUp()) {
            $current = $this->stats->refresh();
            $this->consecutiveLosses = 0;
            $log("Leveled up to {$current->level} after a loss — rage refilled, retrying stronger.");
        }

        $this->optimizeGear($current->level, $log);

        if ($this->consecutiveLosses >= self::MAX_CONSECUTIVE_LOSSES) {
            $this->outmatched[$sighting->name] = true;
            $log("Giving up on {$sighting->name} after {$this->consecutiveLosses} straight losses.");
        }

        return $current;
    }

    /**
     * True once every configured target has beaten us into the outmatched
     * list — nothing left to try, so the pass ends rather than wandering.
     */
    private function allTargetsOutmatched(): bool
    {
        foreach ($this->config->mobNames as $name) {
            if (! isset($this->outmatched[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Auto-equip pass (smart mode). Gear is an optimisation, never a reason to
     * end a run — a failure here is logged and shrugged off.
     *
     * @param  Closure(string): void  $log
     */
    private function optimizeGear(int $characterLevel, Closure $log): void
    {
        if ($this->gearManager === null) {
            return;
        }

        try {
            $this->gearManager->optimize($characterLevel, $log);
        } catch (SessionCollisionException $exception) {
            throw $exception;
        } catch (GameException $exception) {
            $log("Gear check failed: {$exception->getMessage()}");
        }
    }

    /**
     * End-of-run junk sweep (opt-in). Never lets a cleanup failure mask the
     * run's own outcome or exception.
     *
     * @param  Closure(string): void  $log
     */
    private function dropJunk(Closure $log): void
    {
        if (! $this->config->dropJunk || $this->junkDropper === null) {
            return;
        }

        try {
            $summary = $this->junkDropper->dropJunk($log);
            $log("Junk sweep: dropped {$summary->dropped} of {$summary->scanned} loose items.");
        } catch (GameException $exception) {
            $log("Junk sweep failed: {$exception->getMessage()}");
        }
    }

    /**
     * The "level if rage low" policy: leveling refills rage for free.
     * Returns the refreshed rage after a level-up, null when unavailable.
     */
    private function recoverRage(Closure $log): ?int
    {
        if (! $this->config->levelUp || ! $this->stats->tryLevelUp()) {
            return null;
        }

        $log('Leveled up — rage refilled.');

        return $this->stats->refresh()->rage;
    }

    private function tally(BattleEvent $event, MobSighting $sighting, Closure $log): void
    {
        match ($event->outcome) {
            BattleOutcome::Win => $this->wins++,
            BattleOutcome::Loss => $this->losses++,
            default => $this->errors++,
        };

        $log(match ($event->outcome) {
            BattleOutcome::Win => sprintf(
                'Beat %s (+%s exp)%s',
                $sighting->name,
                number_format((int) $event->exp_gained),
                $event->drop_name !== null ? " — found {$event->drop_name}" : '',
            ),
            BattleOutcome::Loss => "Lost to {$sighting->name}",
            default => "Attack on {$sighting->name} failed: {$event->fail_reason}",
        });
    }

    private function summary(string $reason, RunEndReason $endReason): MobRunSummary
    {
        return new MobRunSummary(
            $this->wins,
            $this->losses,
            $this->errors,
            $reason,
            $endReason,
            $this->targetsRespawnPending(),
            $this->rageShortfall(),
        );
    }
}
