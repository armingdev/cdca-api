<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Data\RoomBlob;
use App\Game\Exceptions\DesyncException;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\GatedRoomException;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Game\World\RoomObservationRecorder;
use App\Game\World\TeleportService;
use App\Models\Character;
use App\Models\Room;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:map {character : Character id or name}
    {--max-rooms=0 : Stop after verifying this many rooms (0 = unlimited)}
    {--reset : Teleport to the start room before mapping}
    {--teleports : When the walkable component runs dry, jump to the next teleport anchor and keep going}')]
#[Description('Spider the world graph: walk every unknown or unverified room and record exits + mobs')]
class MapCommand extends Command
{
    private const int MAX_CONSECUTIVE_DESYNCS = 3;

    /**
     * Rooms still needing a live visit, as a set of ids. Seeded rooms start
     * with a null last_verified_at, so a freshly seeded world is walked in
     * full — the seed's exits only make the routing smarter, they don't count
     * as verification.
     *
     * @var array<int, mixed>
     */
    private array $unverified = [];

    /**
     * Teleport anchors already jumped with this run, so the spider never
     * ping-pongs between the same two components.
     *
     * @var array<int, bool>
     */
    private array $usedAnchors = [];

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
        $teleports = $this->option('teleports') ? TeleportService::forCharacter($character) : null;

        if ($teleports !== null) {
            $sync = $teleports->syncAnchors();
            $this->line("{$sync->total()} teleport anchors available ({$sync->withoutDestination} with an unknown landing room).");
        }

        $this->unverified = Room::whereNull('last_verified_at')
            ->where('is_gated', false)
            ->pluck('id')
            ->flip()
            ->all();

        $this->info(sprintf(
            'Mapping as %s — %d rooms known, %d awaiting verification.',
            $character->name,
            $graph->count(),
            count($this->unverified),
        ));

        $blob = $this->option('reset') ? $navigator->resetToStart() : $navigator->loadCurrentRoom();
        $graph->addRoom($blob->curRoom, $blob->exits);
        $current = $blob->curRoom;

        $verified = 1;
        $reportedAt = 0;
        $gated = 0;
        $desyncs = 0;
        $startedAt = microtime(true);
        unset($this->unverified[$current]);

        $isTarget = fn (int $roomId): bool => ! $graph->has($roomId) || isset($this->unverified[$roomId]);

        while ($maxRooms === 0 || $verified < $maxRooms) {
            $next = $this->firstTargetExit($graph, $current);

            try {
                if ($next !== null) {
                    $blob = $navigator->stepTo($next, $current);
                    $walked = [$blob->curRoom];
                } else {
                    $path = $graph->pathToNearest($current, $isTarget);

                    if ($path === null) {
                        // Walking is exhausted, but teleport anchors open
                        // components no walk reaches (~90% of the seeded world
                        // hangs off them) — jump and carry on.
                        $jumped = $teleports !== null ? $this->jumpToNextComponent($teleports, $graph) : null;

                        if ($jumped !== null) {
                            $graph->addRoom($jumped->curRoom, $jumped->exits);
                            $current = $jumped->curRoom;

                            continue;
                        }

                        $this->info(sprintf(
                            'No reachable targets remain — component fully mapped (%d unreachable rooms still unverified).',
                            count($this->unverified),
                        ));
                        break;
                    }

                    $blob = $navigator->walk($path);

                    if ($blob === null) {
                        break;
                    }

                    $walked = array_slice($path, 1);
                }
            } catch (GatedRoomException $exception) {
                $recorder->recordGated($exception->roomId, $exception->reason, $character);
                $graph->addRoom($exception->roomId, []);
                unset($this->unverified[$exception->roomId]);
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

            // Every room stepped through was recorded and verified — a walk
            // through unverified territory clears the whole path.
            foreach ($walked as $roomId) {
                if (isset($this->unverified[$roomId]) || ! $graph->has($roomId)) {
                    $verified++;
                }

                unset($this->unverified[$roomId]);
            }

            $graph->addRoom($blob->curRoom, $blob->exits);
            $current = $blob->curRoom;

            if ($verified - $reportedAt >= 25) {
                $reportedAt = $verified;
                $this->reportProgress($verified, $gated, $startedAt);
            }
        }

        $elapsed = max(microtime(true) - $startedAt, 1);

        $this->info(sprintf(
            'Done. %d rooms verified this run (%d gated), %d rooms in the database, %d still unverified, %.1f rooms/min.',
            $verified,
            $gated,
            Room::count(),
            count($this->unverified),
            $verified / ($elapsed / 60),
        ));

        return self::SUCCESS;
    }

    /**
     * Jump to the next teleport anchor worth spidering from: one whose landing
     * room is unknown to us (discovery — items are free and not consumed, so a
     * blind jump costs nothing) or still unverified. Each anchor is used at
     * most once per run, so this always terminates.
     */
    private function jumpToNextComponent(TeleportService $teleports, RoomGraph $graph): ?RoomBlob
    {
        $candidates = [...$teleports->discoveryTargets(), ...$teleports->usableAnchors()];

        foreach ($candidates as $anchor) {
            if (isset($this->usedAnchors[$anchor->id])) {
                continue;
            }

            $this->usedAnchors[$anchor->id] = true;

            $landing = $anchor->room_id;

            if ($landing !== null && $graph->has($landing) && ! isset($this->unverified[$landing])) {
                // Known, already-verified landing room: only worth the jump if
                // its component still holds unverified rooms.
                if ($graph->pathToNearest($landing, fn (int $roomId): bool => ! $graph->has($roomId) || isset($this->unverified[$roomId])) === null) {
                    continue;
                }
            }

            try {
                $blob = $teleports->jump($anchor);
                $this->line("Teleported with {$anchor->name} → {$blob->curRoom} {$blob->name}.");

                return $blob;
            } catch (GameException $exception) {
                $this->warn("{$anchor->name}: {$exception->getMessage()}");
            }
        }

        return null;
    }

    /**
     * The first exit of a room leading somewhere we have never loaded live —
     * unknown to the graph or seeded but unverified.
     */
    private function firstTargetExit(RoomGraph $graph, int $roomId): ?int
    {
        foreach ($graph->neighbors($roomId) as $neighbor) {
            if (! $graph->has($neighbor) || isset($this->unverified[$neighbor])) {
                return $neighbor;
            }
        }

        return null;
    }

    private function reportProgress(int $verified, int $gated, float $startedAt): void
    {
        $elapsed = max(microtime(true) - $startedAt, 1);

        $this->line(sprintf(
            '%d rooms verified (%d gated) — %.1f rooms/min, %d remaining',
            $verified,
            $gated,
            $verified / ($elapsed / 60),
            count($this->unverified),
        ));
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
