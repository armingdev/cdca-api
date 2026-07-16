<?php

namespace App\Game\Engine;

use App\Game\Combat\AttackService;
use App\Game\Combat\StatsService;
use App\Game\Data\MobSighting;
use App\Game\Data\RoomBlob;
use App\Game\Enums\BattleOutcome;
use App\Game\Exceptions\GameException;
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
 * RunMobJob (queued) — callbacks carry logging, external stop signals, and
 * per-battle hooks.
 */
class MobRunner
{
    private int $wins = 0;

    private int $losses = 0;

    private int $errors = 0;

    public function __construct(
        private readonly Character $character,
        private readonly MobRunConfig $config,
        private readonly Navigator $navigator,
        private readonly AttackService $attacker,
        private readonly StatsService $stats,
    ) {}

    public static function forCharacter(Character $character, MobRunConfig $config): self
    {
        return new self(
            $character,
            $config,
            Navigator::forCharacter($character),
            AttackService::forCharacter($character),
            StatsService::forCharacter($character),
        );
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): bool|null  $shouldStop  external stop signal, polled every iteration
     * @param  Closure(BattleEvent): void|null  $onBattle
     *
     * @throws GameException when the targets have no mapped rooms
     */
    public function run(?Closure $log = null, ?Closure $shouldStop = null, ?Closure $onBattle = null): MobRunSummary
    {
        $log ??= fn (string $message) => null;

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
            if ($shouldStop !== null && $shouldStop()) {
                return $this->summary('Stop requested.', externallyStopped: true);
            }

            if ($current->rage < $this->config->stopRage) {
                $recovered = $this->recoverRage($log);

                if ($recovered === null || $recovered < $this->config->stopRage) {
                    return $this->summary("Rage below the {$this->config->stopRage} floor.");
                }

                $current = $this->stats->refresh();
            }

            if ($this->config->maxKills > 0 && $this->wins >= $this->config->maxKills) {
                return $this->summary("Reached {$this->config->maxKills} kills.");
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
                return $this->summary('No live targets remain in any known room.');
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

    private function summary(string $reason, bool $externallyStopped = false): MobRunSummary
    {
        return new MobRunSummary($this->wins, $this->losses, $this->errors, $reason, $externallyStopped);
    }
}
