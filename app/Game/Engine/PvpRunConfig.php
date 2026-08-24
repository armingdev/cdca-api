<?php

namespace App\Game\Engine;

/**
 * Per-run PvP options — stored as the run's jsonb config.
 *
 * The five PvP modes share this shape and differ only in which target-source
 * field they read (`targets`, `attackListId`, `crewGameId`, brawl type).
 */
final readonly class PvpRunConfig
{
    /**
     * @param  list<string>  $targets  player names, for ad-hoc attack-list runs
     */
    public function __construct(
        public array $targets = [],
        public ?int $attackListId = null,
        public ?int $crewGameId = null,
        public int $attacksPerTarget = 1,
        public int $stopRage = 2500,
        public string $message = '',
        public bool $skipTooStrong = true,
        public bool $autoEnterBrawl = false,
        public ?int $maxAttacks = null,
        public int $cooldownMinutes = 60,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            targets: array_values($config['targets'] ?? []),
            attackListId: isset($config['attack_list_id']) ? (int) $config['attack_list_id'] : null,
            crewGameId: isset($config['crew_game_id']) ? (int) $config['crew_game_id'] : null,
            attacksPerTarget: (int) ($config['attacks_per_target'] ?? 1),
            stopRage: (int) ($config['stop_rage'] ?? 2500),
            message: (string) ($config['message'] ?? ''),
            skipTooStrong: (bool) ($config['skip_too_strong'] ?? true),
            autoEnterBrawl: (bool) ($config['auto_enter_brawl'] ?? false),
            maxAttacks: isset($config['max_attacks']) ? (int) $config['max_attacks'] : null,
            // Time Warp (skill 3017, Affliction) halves the effective window,
            // but only for characters that have it — never assume globally.
            cooldownMinutes: (int) ($config['cooldown_minutes'] ?? 60),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'targets' => $this->targets,
            'attack_list_id' => $this->attackListId,
            'crew_game_id' => $this->crewGameId,
            'attacks_per_target' => $this->attacksPerTarget,
            'stop_rage' => $this->stopRage,
            'message' => $this->message,
            'skip_too_strong' => $this->skipTooStrong,
            'auto_enter_brawl' => $this->autoEnterBrawl,
            'max_attacks' => $this->maxAttacks,
            'cooldown_minutes' => $this->cooldownMinutes,
        ];
    }
}
