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

    private int $wins = 0;

    private int $losses = 0;

    private int $errors = 0;

    /** Kills carried over from earlier cycles of the same run, counted against max_kills. */
    private int $winsBaseline = 0;

    private int $consecutiveLosses = 0;

    private ?string $lossStreakMob = null;

    /** @var array<string, true> mob names smart mode has given up on for this run */
    private array $outmatched = [];

    public function __construct(
        private readonly Character $character,
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
            $character,
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
     * @param  int  $killsAlreadyDone  kills from earlier cycles, so max_kills spans pauses/waits
     *
     * @throws GameException when the targets have no mapped rooms
     */
    public function run(
        ?Closure $log = null,
        ?Closure $signal = null,
        ?Closure $onBattle = null,
        int $killsAlreadyDone = 0,
    ): MobRunSummary {
        $log ??= fn (string $message) => null;
        $this->winsBaseline = max(0, $killsAlreadyDone);

        try {
            return $this->loop($log, $signal, $onBattle);
        } finally {
            $this->dropJunk($log);
        }
    }

    /**
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    private function loop(Closure $log, ?Closure $signal, ?Closure $onBattle): MobRunSummary
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

            if ($sighting !== null) {
                $event = $this->attacker->attack($sighting);
                $this->tally($event, $sighting, $log);
                $onBattle?->__invoke($event);

                $current = $this->stats->refresh();

                if ($event->outcome === BattleOutcome::Win) {
                    $this->consecutiveLosses = 0;
                    $this->lossStreakMob = null;
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

            $exhausted[$blob->curRoom] = true;

            $plan = $planner->planToNearest(
                $blob->curRoom,
                fn (int $roomId): bool => $targetRooms->contains($roomId) && ! isset($exhausted[$roomId]),
                $anchors,
            );

            if ($plan === null) {
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
                $exhausted[end($plan->walkPath)] = true;
                $blob = $this->navigator->loadCurrentRoom();
            }
        }
    }

    private function liveTarget(RoomBlob $blob): ?MobSighting
    {
        foreach ($blob->mobs as $sighting) {
            if ($sighting->isDead || isset($this->outmatched[$sighting->name])) {
                continue;
            }

            if (in_array($sighting->name, $this->config->mobNames, true)) {
                return $sighting;
            }
        }

        return null;
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
        return new MobRunSummary($this->wins, $this->losses, $this->errors, $reason, $endReason);
    }
}
