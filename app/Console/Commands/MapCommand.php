<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Exceptions\DesyncException;
use App\Game\Exceptions\GatedRoomException;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Game\World\RoomObservationRecorder;
use App\Models\Character;
use App\Models\Room;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:map {character : Character id or name}
    {--max-rooms=0 : Stop after verifying this many rooms (0 = unlimited)}
    {--reset : Teleport to the start room before mapping}')]
#[Description('Spider the world graph: walk every reachable room and record exits + mobs')]
class MapCommand extends Command
{
    private const int MAX_CONSECUTIVE_DESYNCS = 3;

    public function handle(RoomObservationRecorder $recorder, LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $navigator = Navigator::forCharacter($character);
        $graph = RoomGraph::fromDatabase();
        $maxRooms = (int) $this->option('max-rooms');

        $this->info("Mapping as {$character->name} — {$graph->count()} rooms already known.");

        $blob = $this->option('reset') ? $navigator->resetToStart() : $navigator->loadCurrentRoom();
        $graph->addRoom($blob->curRoom, $blob->exits);
        $current = $blob->curRoom;

        $verified = 1;
        $gated = 0;
        $desyncs = 0;
        $startedAt = microtime(true);

        while ($maxRooms === 0 || $verified < $maxRooms) {
            $next = $this->firstUnexplored($graph, $current);

            try {
                if ($next !== null) {
                    $blob = $navigator->stepTo($next, $current);
                } else {
                    $path = $graph->pathToNearest(
                        $current,
                        fn (int $roomId): bool => ! $graph->has($roomId)
                            || $this->firstUnexplored($graph, $roomId) !== null,
                    );

                    if ($path === null) {
                        $this->info('No unexplored exits remain — reachable component fully mapped.');
                        break;
                    }

                    $blob = $navigator->walk($path);

                    if ($blob === null) {
                        break;
                    }
                }
            } catch (GatedRoomException $exception) {
                $recorder->recordGated($exception->roomId, $exception->reason, $character);
                $graph->addRoom($exception->roomId, []);
                $gated++;
                $this->warn("Room {$exception->roomId} is gated: {$exception->reason}");

                // A walk may have partially executed — resync our position.
                $blob = $navigator->loadCurrentRoom();
                $graph->addRoom($blob->curRoom, $blob->exits);
                $current = $blob->curRoom;

                continue;
            } catch (DesyncException $exception) {
                $desyncs++;
                $this->warn($exception->getMessage());

                if ($desyncs > self::MAX_CONSECUTIVE_DESYNCS) {
                    $this->warn('Too many desyncs — teleporting to the start room.');
                    $blob = $navigator->resetToStart();
                } else {
                    $blob = $navigator->loadCurrentRoom();
                }

                $graph->addRoom($blob->curRoom, $blob->exits);
                $current = $blob->curRoom;

                continue;
            }

            $desyncs = 0;

            if (! $graph->has($blob->curRoom)) {
                $verified++;
            }

            $graph->addRoom($blob->curRoom, $blob->exits);
            $current = $blob->curRoom;

            if ($verified % 25 === 0) {
                $this->reportProgress($verified, $gated, $startedAt);
            }
        }

        $elapsed = max(microtime(true) - $startedAt, 1);

        $this->info(sprintf(
            'Done. %d rooms visited this run (%d gated), %d rooms in the database, %.1f rooms/min.',
            $verified,
            $gated,
            Room::count(),
            $verified / ($elapsed / 60),
        ));

        return self::SUCCESS;
    }

    /**
     * The first exit of a room leading somewhere we have never loaded.
     */
    private function firstUnexplored(RoomGraph $graph, int $roomId): ?int
    {
        foreach ($graph->neighbors($roomId) as $neighbor) {
            if (! $graph->has($neighbor)) {
                return $neighbor;
            }
        }

        return null;
    }

    private function reportProgress(int $verified, int $gated, float $startedAt): void
    {
        $elapsed = max(microtime(true) - $startedAt, 1);

        $this->line(sprintf(
            '%d rooms visited (%d gated) — %.1f rooms/min',
            $verified,
            $gated,
            $verified / ($elapsed / 60),
        ));
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
