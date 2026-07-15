<?php

namespace App\Game\Exceptions;

/**
 * The game refused entry to a room (key/buff requirement). A first-class
 * observation: the room exists but is gated — record it, don't retry.
 */
class GatedRoomException extends GameException
{
    public function __construct(
        public readonly int $roomId,
        public readonly string $reason,
    ) {
        parent::__construct("Entry to room {$roomId} refused: {$reason}");
    }
}
