<?php

namespace App\Game\Data;

/**
 * Parsed ajax_changeroomb.php response — the heart of navigation.
 */
final readonly class RoomBlob
{
    /**
     * @param  array<string, int>  $exits  direction => neighbor room id (exits only)
     * @param  list<MobSighting>  $mobs
     * @param  array<string, mixed>|null  $doors
     * @param  string|null  $questHelpDirection  quest-helper compass while "find my
     *                                           target" is on: north/south/east/west, or null when there is no
     *                                           pointer — in the target room (or helper off)
     * @param  int|null  $tavernRoomId  set when the room is a tavern: the room id its
     *                                  "Make {name} my home!" link would set as the home tavern
     */
    public function __construct(
        public int $curRoom,
        public string $name,
        public array $exits,
        public array $mobs,
        public ?array $doors,
        public string $error,
        public ?string $questHelpDirection = null,
        public ?int $tavernRoomId = null,
    ) {}

    /**
     * Taverns are the anchor for the free world.php?teleport=1 jump, so the
     * spider records them as it walks past.
     */
    public function isTavern(): bool
    {
        return $this->tavernRoomId !== null;
    }

    public function hasError(): bool
    {
        return $this->error !== '';
    }

    /**
     * @return list<int>
     */
    public function neighborIds(): array
    {
        return array_values($this->exits);
    }

    public function exitTo(int $roomId): bool
    {
        return in_array($roomId, $this->exits, true);
    }
}
