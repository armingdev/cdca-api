<?php

namespace App\Game\Engine;

/**
 * Per-run mob-mode options — stored as the run's jsonb config.
 *
 * A "pass" (run) is one full sweep: attack the selected mobs until none are
 * left alive or the rage floor is hit. run_count bounds how many passes each
 * character performs; 0 (the default) farms indefinitely, parking between
 * passes to wait out respawns. attack_interval_seconds sets that wait.
 *
 * `smart` is the low-level survival mode: auto-equip the best backpack gear,
 * level up after a loss (levels grant atk/def), and give up on a mob after
 * repeated losses instead of grinding rage to zero.
 */
final readonly class MobRunConfig
{
    /**
     * @param  list<string>  $mobNames
     * @param  int  $runCount  passes to perform; 0 = farm indefinitely
     */
    public function __construct(
        public array $mobNames,
        public int $stopRage = 2500,
        public int $maxKills = 0,
        public bool $levelUp = false,
        public bool $dropJunk = false,
        public int $runCount = 0,
        public ?int $attackIntervalSeconds = null,
        public bool $smart = false,
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
            dropJunk: (bool) ($config['drop_junk'] ?? false),
            runCount: (int) ($config['run_count'] ?? 0),
            attackIntervalSeconds: isset($config['attack_interval_seconds'])
                ? (int) $config['attack_interval_seconds']
                : null,
            smart: (bool) ($config['smart'] ?? false),
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
            'drop_junk' => $this->dropJunk,
            'run_count' => $this->runCount,
            'attack_interval_seconds' => $this->attackIntervalSeconds,
            'smart' => $this->smart,
        ];
    }
}
