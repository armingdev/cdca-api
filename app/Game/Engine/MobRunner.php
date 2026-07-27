<?php

namespace App\Game\Engine;

use App\Game\Combat\AttackService;
use App\Game\Combat\StatsService;
use App\Game\Data\MobSighting;
use App\Game\Data\RoomBlob;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\RunSignal;
use App\Game\Exceptions\GameException;
use App\Game\Items\JunkDropper;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
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
    private int $wins = 0;

    private int $losses = 0;

    private int $errors = 0;

    /** Kills carried over from earlier cycles of the same run, counted against max_kills. */
    private int $winsBaseline = 0;

    public function __construct(
        private readonly Character $character,
        private readonly MobRunConfig $config,
        private readonly Navigator $navigator,
        private readonly AttackService $attacker,
        private readonly StatsService $stats,
        private readonly ?JunkDropper $junkDropper = null,
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
        $current = $this->stats->refresh();
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
                $blob = $this->navigator->loadCurrentRoom();

                continue;
            }

            $exhausted[$blob->curRoom] = true;

            $path = $graph->pathToNearest(
                $blob->curRoom,
                fn (int $roomId): bool => $targetRooms->contains($roomId) && ! isset($exhausted[$roomId]),
            );

            if ($path === null) {
                return $this->summary('No live targets remain in any known room.', RunEndReason::Completed);
            }

            try {
                $blob = count($path) > 1 ? $this->navigator->walk($path) : $this->navigator->loadCurrentRoom();
                $graph->addRoom($blob->curRoom, $blob->exits);
            } catch (GameException $exception) {
                $log($exception->getMessage());
                $this->errors++;
                $exhausted[end($path)] = true;
                $blob = $this->navigator->loadCurrentRoom();
            }
        }
    }

    private function liveTarget(RoomBlob $blob): ?MobSighting
    {
        foreach ($blob->mobs as $sighting) {
            if (! $sighting->isDead && in_array($sighting->name, $this->config->mobNames, true)) {
                return $sighting;
            }
        }

        return null;
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
