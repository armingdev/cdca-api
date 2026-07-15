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
        );
    }
}
