<?php

namespace App\Game\Engine;

/**
 * Per-run quest-list options — stored as the run's jsonb config.
 */
final readonly class QuestListRunConfig
{
    public function __construct(
        public int $questListId,
        public int $stopRage = 2500,
        public bool $levelUp = false,
        public bool $smart = false,
        public int $respawnWaitSeconds = QuestRunConfig::DEFAULT_RESPAWN_WAIT_SECONDS,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            questListId: (int) ($config['quest_list_id'] ?? 0),
            stopRage: (int) ($config['stop_rage'] ?? 2500),
            levelUp: (bool) ($config['level_up'] ?? false),
            smart: (bool) ($config['smart'] ?? false),
            respawnWaitSeconds: (int) ($config['respawn_wait_seconds'] ?? QuestRunConfig::DEFAULT_RESPAWN_WAIT_SECONDS),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quest_list_id' => $this->questListId,
            'stop_rage' => $this->stopRage,
            'level_up' => $this->levelUp,
            'smart' => $this->smart,
            'respawn_wait_seconds' => $this->respawnWaitSeconds,
        ];
    }
}
