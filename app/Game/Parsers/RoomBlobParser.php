<?php

namespace App\Game\Parsers;

use App\Game\Data\MobSighting;
use App\Game\Data\RoomBlob;
use App\Game\Exceptions\ParseException;

class RoomBlobParser
{
    private const array DIRECTIONS = ['north', 'east', 'south', 'west'];

    public function parse(string $body): RoomBlob
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ParseException('Room blob is not valid JSON: '.substr($body, 0, 200));
        }

        $error = (string) ($data['error'] ?? '');

        if (! array_key_exists('curRoom', $data) && $error === '') {
            throw new ParseException('Room blob is missing curRoom and carries no error: '.substr($body, 0, 200));
        }

        $exits = [];

        foreach (self::DIRECTIONS as $direction) {
            $neighbor = (int) ($data[$direction] ?? 0);

            if ($neighbor > 0) {
                $exits[$direction] = $neighbor;
            }
        }

        $mobs = array_map(
            fn (array $mob): MobSighting => MobSighting::fromArray($mob),
            $data['roomDetailsNew'] ?? [],
        );

        return new RoomBlob(
            curRoom: (int) ($data['curRoom'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            exits: $exits,
            mobs: array_values($mobs),
            doors: is_array($data['doorsData'] ?? null) ? $data['doorsData'] : null,
            error: $error,
            questHelpDirection: $this->questHelpDirection($data),
            tavernRoomId: $this->tavernRoomId($data),
        );
    }

    /**
     * A tavern room carries tavernData with its "Make {name} my home!" link:
     * `<a href="/world.php?teleportupdate=1&tavern=376">…`. Empty for every
     * other room.
     *
     * @param  array<string, mixed>  $data
     */
    private function tavernRoomId(array $data): ?int
    {
        $tavern = $data['tavernData'] ?? null;

        if (is_string($tavern) && preg_match('/tavern=(\d+)/', $tavern, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * The quest-helper compass rides in questHelpData as a d-pad image name
     * ("dpadcenter_south.jpg") while "find my target" is on; null = no pointer.
     *
     * @param  array<string, mixed>  $data
     */
    private function questHelpDirection(array $data): ?string
    {
        $image = $data['questHelpData'] ?? null;

        if (is_string($image) && preg_match('/dpadcenter_(north|south|east|west)/', $image, $m)) {
            return $m[1];
        }

        return null;
    }
}
