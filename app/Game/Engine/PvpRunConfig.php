<?php

namespace App\Game\Engine;

/**
 * Per-run PvP options — stored as the run's jsonb config.
 */
final readonly class PvpRunConfig
{
    /**
     * @param  list<string>  $targets  player names to attack, in order
     */
    public function __construct(
        public array $targets,
        public int $attackRage = 50,
        public int $attacksPerTarget = 1,
        public int $stopRage = 2500,
        public string $message = '',
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            targets: array_values($config['targets'] ?? []),
            attackRage: (int) ($config['attack_rage'] ?? 50),
            attacksPerTarget: (int) ($config['attacks_per_target'] ?? 1),
            stopRage: (int) ($config['stop_rage'] ?? 2500),
            message: (string) ($config['message'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'targets' => $this->targets,
            'attack_rage' => $this->attackRage,
            'attacks_per_target' => $this->attacksPerTarget,
            'stop_rage' => $this->stopRage,
            'message' => $this->message,
        ];
    }
}
