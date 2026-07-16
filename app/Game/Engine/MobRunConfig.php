<?php

namespace App\Game\Engine;

/**
 * Per-run mob-mode options — stored as the run's jsonb config.
 */
final readonly class MobRunConfig
{
    /**
     * @param  list<string>  $mobNames
     */
    public function __construct(
        public array $mobNames,
        public int $stopRage = 2500,
        public int $maxKills = 0,
        public bool $levelUp = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            mobNames: array_values($config['mob_names'] ?? []),
            stopRage: (int) ($config['stop_rage'] ?? 2500),
            maxKills: (int) ($config['max_kills'] ?? 0),
            levelUp: (bool) ($config['level_up'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mob_names' => $this->mobNames,
            'stop_rage' => $this->stopRage,
            'max_kills' => $this->maxKills,
            'level_up' => $this->levelUp,
        ];
    }
}
