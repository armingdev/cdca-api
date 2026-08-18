<?php

namespace App\Game\Data;

/**
 * One option of the Teleport skill's destination dropdown. Names are not
 * unique (two rooms are both "Chuggers Palace Bar") — the room id is the key.
 */
final readonly class TeleportDestination
{
    public function __construct(
        public int $roomId,
        public string $name,
    ) {}
}
