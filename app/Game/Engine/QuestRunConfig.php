<?php

namespace App\Game\Engine;

/**
 * Per-run quest-mode options — stored as the run's jsonb config.
 */
final readonly class QuestRunConfig
{
    /** Default pause before re-checking rooms whose targets were all dead. */
    public const int DEFAULT_RESPAWN_WAIT_SECONDS = 60;

    /**
     * Skip steps wanting a purchased item (a Quest Shard) by default: farming
     * cannot satisfy them, so the alternative is grinding to a halt.
     */
    public const bool DEFAULT_SKIP_SHARD_QUESTS = true;

    public function __construct(
        public string $npcName,
        public int $questId,
        public int $stopRage = 2500,
        public bool $levelUp = false,
        public bool $smart = false,
        public int $respawnWaitSeconds = self::DEFAULT_RESPAWN_WAIT_SECONDS,
        public bool $skipShardQuests = self::DEFAULT_SKIP_SHARD_QUESTS,
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
            smart: (bool) ($config['smart'] ?? false),
            respawnWaitSeconds: (int) ($config['respawn_wait_seconds'] ?? self::DEFAULT_RESPAWN_WAIT_SECONDS),
            skipShardQuests: (bool) ($config['skip_shard_quests'] ?? self::DEFAULT_SKIP_SHARD_QUESTS),
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
            'smart' => $this->smart,
            'respawn_wait_seconds' => $this->respawnWaitSeconds,
            'skip_shard_quests' => $this->skipShardQuests,
        ];
    }
}
