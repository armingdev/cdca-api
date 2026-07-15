<?php

namespace App\Game\World;

use App\Game\Data\RoomBlob;
use App\Game\Exceptions\DesyncException;
use App\Game\Exceptions\GatedRoomException;
use App\Game\Http\GameClient;
use App\Game\Parsers\RoomBlobParser;
use App\Models\Character;

/**
 * Executes movement for one character: one move = one ajax_changeroomb.php
 * call by absolute room id (neighbor only). Every response flows through the
 * observation recorder, and the game-reported position is verified after
 * every step (desync guard).
 */
class Navigator
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly RoomBlobParser $parser,
        private readonly RoomObservationRecorder $recorder,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(RoomBlobParser::class),
            app(RoomObservationRecorder::class),
        );
    }

    public function loadCurrentRoom(): RoomBlob
    {
        return $this->request(0, 0);
    }

    /**
     * Move to a neighboring room and verify we actually landed there.
     */
    public function stepTo(int $target, int $from): RoomBlob
    {
        $blob = $this->request($target, $from);

        if ($blob->curRoom !== $target) {
            throw DesyncException::positionMismatch($target, $blob->curRoom);
        }

        return $blob;
    }

    /**
     * Walk a BFS path (list of room ids beginning at the current room).
     */
    public function walk(array $path): ?RoomBlob
    {
        $blob = null;

        for ($i = 1; $i < count($path); $i++) {
            $blob = $this->stepTo($path[$i], $path[$i - 1]);
        }

        return $blob;
    }

    /**
     * GET /world?room=1 teleports to the world start from anywhere (verified;
     * works only for room 1) — the escape hatch when trapped or desynced.
     */
    public function resetToStart(): RoomBlob
    {
        $this->client->get('world', ['room' => (int) config('outwar.start_room_id')]);

        return $this->loadCurrentRoom();
    }

    private function request(int $room, int $lastRoom): RoomBlob
    {
        $response = $this->client->get('ajax_changeroomb.php', [
            'room' => $room,
            'lastroom' => $lastRoom,
        ]);

        $blob = $this->parser->parse($response->body());

        if ($blob->hasError()) {
            if (str_contains($blob->error, '#301')) {
                throw DesyncException::hashError($blob->error);
            }

            throw new GatedRoomException($room, $blob->error);
        }

        $this->recorder->record($blob, $this->character);
        $this->character->update(['current_room_id' => $blob->curRoom]);

        return $blob;
    }
}
