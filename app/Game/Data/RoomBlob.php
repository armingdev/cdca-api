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
     */
    public function __construct(
        public int $curRoom,
        public string $name,
        public array $exits,
        public array $mobs,
        public ?array $doors,
        public string $error,
    ) {}

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
