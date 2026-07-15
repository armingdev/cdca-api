<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Combat\AttackService;
use App\Game\Combat\StatsService;
use App\Game\Data\MobSighting;
use App\Game\Data\RoomBlob;
use App\Game\Data\UserStats;
use App\Game\Enums\BattleOutcome;
use App\Game\Exceptions\GameException;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Models\Character;
use App\Models\Mob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:attack {character : Character id or name}
    {--mob=* : Exact mob name(s) to farm}
    {--stop-rage=2500 : Stop (or level) when rage drops below this floor}
    {--max-kills=0 : Stop after this many wins (0 = unlimited)}
    {--level-up : When rage is low, try levelup.php (refills rage) before stopping}')]
#[Description('Mob mode: pathfind to the target mobs\' rooms and attack-loop until a stop condition')]
class AttackCommand extends Command
{
    private int $wins = 0;

    private int $losses = 0;

    private int $errors = 0;

    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        $targetNames = array_values((array) $this->option('mob'));

        if ($targetNames === []) {
            $this->error('Pass at least one --mob="Exact Mob Name".');

            return self::FAILURE;
        }

        $targetRooms = Mob::whereIn('name', $targetNames)
            ->with('rooms:id')
            ->get()
            ->flatMap(fn (Mob $mob) => $mob->rooms->pluck('id'))
            ->unique()
            ->values();

        if ($targetRooms->isEmpty()) {
            $this->error('No known rooms for those mobs — map the area first (outwar:map) or check the names.');

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $navigator = Navigator::forCharacter($character);
        $attacker = AttackService::forCharacter($character);
        $stats = StatsService::forCharacter($character);
        $graph = RoomGraph::fromDatabase();
        $stopRage = (int) $this->option('stop-rage');
        $maxKills = (int) $this->option('max-kills');

        $this->info(sprintf(
            'Farming %s across %d rooms as %s (stop below %d rage).',
            implode(', ', $targetNames),
            $targetRooms->count(),
            $character->name,
            $stopRage,
        ));

        $current = $stats->refresh();
        $blob = $navigator->loadCurrentRoom();
        $graph->addRoom($blob->curRoom, $blob->exits);
        $exhausted = [];

        while (true) {
            if ($current->rage < $stopRage) {
                $recovered = $this->recoverRage($stats);

                if ($recovered === null || $recovered->rage < $stopRage) {
                    $this->info("Rage {$current->rage} is below the {$stopRage} floor — stopping.");
                    break;
                }

                $current = $recovered;
            }

            if ($maxKills > 0 && $this->wins >= $maxKills) {
                $this->info("Reached {$maxKills} kills — stopping.");
                break;
            }

            $sighting = $this->liveTarget($blob, $targetNames);

            if ($sighting !== null) {
                $event = $attacker->attack($sighting);
                $this->tally($event->outcome, $sighting, $event->exp_gained, $event->drop_name, $event->fail_reason);

                $current = $stats->refresh();
                $blob = $navigator->loadCurrentRoom();

                continue;
            }

            $exhausted[$blob->curRoom] = true;

            $path = $graph->pathToNearest(
                $blob->curRoom,
                fn (int $roomId): bool => $targetRooms->contains($roomId) && ! isset($exhausted[$roomId]),
            );

            if ($path === null) {
                $this->info('No live targets remain in any known room — stopping.');
                break;
            }

            try {
                $blob = count($path) > 1 ? $navigator->walk($path) : $navigator->loadCurrentRoom();
                $graph->addRoom($blob->curRoom, $blob->exits);
            } catch (GameException $exception) {
                $this->warn($exception->getMessage());
                $this->errors++;
                $exhausted[end($path)] = true;
                $blob = $navigator->loadCurrentRoom();
            }
        }

        $this->info(sprintf('Done: %d wins / %d losses / %d errors.', $this->wins, $this->losses, $this->errors));

        return self::SUCCESS;
    }

    /**
     * The first still-alive target mob in the current room.
     *
     * @param  list<string>  $targetNames
     */
    private function liveTarget(RoomBlob $blob, array $targetNames): ?MobSighting
    {
        foreach ($blob->mobs as $sighting) {
            if (! $sighting->isDead && in_array($sighting->name, $targetNames, true)) {
                return $sighting;
            }
        }

        return null;
    }

    /**
     * The "level if rage low" policy: leveling up refills rage for free.
     * Returns the refreshed stats after a successful level-up, null otherwise.
     */
    private function recoverRage(StatsService $stats): ?UserStats
    {
        if (! $this->option('level-up') || ! $stats->tryLevelUp()) {
            return null;
        }

        $this->info('Leveled up — rage refilled.');

        return $stats->refresh();
    }

    private function tally(BattleOutcome $outcome, MobSighting $sighting, ?int $exp, ?string $drop, ?string $failReason): void
    {
        match ($outcome) {
            BattleOutcome::Win => $this->wins++,
            BattleOutcome::Loss => $this->losses++,
            default => $this->errors++,
        };

        $line = match ($outcome) {
            BattleOutcome::Win => sprintf('Beat %s (+%s exp)%s', $sighting->name, number_format((int) $exp), $drop !== null ? " — found {$drop}" : ''),
            BattleOutcome::Loss => "Lost to {$sighting->name}",
            default => "Attack on {$sighting->name} failed: {$failReason}",
        };

        $this->line($line);
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
