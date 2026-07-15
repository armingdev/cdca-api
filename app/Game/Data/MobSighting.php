<?php

namespace App\Game\Data;

/**
 * One entry of the room blob's roomDetailsNew[] — a mob as currently rendered
 * in a room. The encid is regenerated on every room load (single-use).
 */
final readonly class MobSighting
{
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
        );
    }

    public function isRaid(): bool
    {
        return $this->type === 1 || $this->canForm;
    }
}
