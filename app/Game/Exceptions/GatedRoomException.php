<?php

namespace App\Game\Exceptions;

use Illuminate\Support\Str;

/**
 * The game refused entry to a room (key/buff requirement). A first-class
 * observation: the room exists but is gated — record it, don't retry.
 *
 * The game appends navigation links to the reason ("…carrying Triworld
 * Teleporter. <p>Head <a href=…>back to last room</a>…"); only the sentence
 * before them is kept, since it travels into last_activity and the run log.
 */
class GatedRoomException extends GameException
{
    public readonly string $reason;

    public function __construct(
        public readonly int $roomId,
        string $reason,
    ) {
        $this->reason = Str::squish(html_entity_decode(strip_tags(Str::before($reason, '<p>'))));

        parent::__construct("Entry to room {$roomId} refused: {$this->reason}");
    }

    /** The item the game wants carried, e.g. "Triworld Teleporter", when it says so. */
    public function requiredItem(): ?string
    {
        return preg_match('/must be carrying (?:an? )?(.+?)(?: to enter\b.*)?\.?$/i', $this->reason, $matches) === 1
            ? $matches[1]
            : null;
    }
}
