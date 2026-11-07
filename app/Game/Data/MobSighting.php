<?php

namespace App\Game\Data;

/**
 * One entry of the room blob's roomDetailsNew[] — a mob as currently rendered
 * in a room. The encid is regenerated on every room load (single-use).
 */
final readonly class MobSighting
{
    /** roomDetailsNew `type`: 1 raid/god, 2 ordinary mob, 3 spawned mob (VERIFIED 2026-11-07). */
    public const int TYPE_SPAWN = 3;

    public function __construct(
        public string $name,
        public int $level,
        public int $rageCost,
        public int $mobId,
        public int $spawnId,
        public string $hash,
        public string $encid,
        public ?string $image,
        public bool $isDead,
        public int $type,
        public bool $canForm,
        public ?string $lastKilledBy,
        /** A talk-only NPC: rendered with an encid, but the game refuses the attack. */
        public bool $noAttack = false,
        /** Spawned mobs only (type 3): the player whose kill drew it out. */
        public ?string $spawnedBy = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            level: (int) $data['level'],
            rageCost: (int) ($data['rage'] ?? 0),
            mobId: (int) $data['mobId'],
            spawnId: (int) ($data['spawnId'] ?? 0),
            hash: (string) ($data['h'] ?? ''),
            encid: (string) ($data['encid'] ?? ''),
            image: $data['image'] ?? null,
            isDead: (bool) ($data['isDead'] ?? false),
            type: (int) ($data['type'] ?? 0),
            canForm: (bool) ($data['canForm'] ?? false),
            lastKilledBy: $data['lastKilledBy'] ?? null,
            noAttack: (bool) ($data['noAttack'] ?? false),
            spawnedBy: isset($data['spawnedBy']) ? (string) $data['spawnedBy'] : null,
        );
    }

    public function isRaid(): bool
    {
        return $this->type === 1 || $this->canForm;
    }

    /**
     * A mob that only exists once spawned — drawn out by killing its
     * room-mates. It stays listed after death (isDead), unlike ordinary mobs.
     */
    public function isSpawn(): bool
    {
        return $this->type === self::TYPE_SPAWN || $this->spawnedBy !== null;
    }
}
