<?php

namespace App\Game\Engine;

/**
 * Per-run quest-mode options — stored as the run's jsonb config.
 */
final readonly class QuestRunConfig
{
    public function __construct(
        public string $npcName,
        public int $questId,
        public int $stopRage = 2500,
        public bool $levelUp = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            npcName: (string) ($config['npc_name'] ?? ''),
            questId: (int) ($config['quest_id'] ?? 0),
            stopRage: (int) ($config['stop_rage'] ?? 2500),
            levelUp: (bool) ($config['level_up'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'npc_name' => $this->npcName,
            'quest_id' => $this->questId,
            'stop_rage' => $this->stopRage,
            'level_up' => $this->levelUp,
        ];
    }
}
