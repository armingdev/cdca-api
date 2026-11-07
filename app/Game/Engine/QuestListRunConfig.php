<?php

namespace App\Game\Engine;

/**
 * Per-run quest-list options — stored as the run's jsonb config.
 *
 * A run plays one or more lists back to back, in the given order.
 */
final readonly class QuestListRunConfig
{
    /** How many lists one run may chain. */
    public const int MAX_LISTS = 10;

    /**
     * @param  list<int>  $questListIds  in play order
     */
    public function __construct(
        public array $questListIds,
        public int $stopRage = 2500,
        public bool $levelUp = false,
        public bool $smart = false,
        public int $respawnWaitSeconds = QuestRunConfig::DEFAULT_RESPAWN_WAIT_SECONDS,
        public bool $skipShardQuests = QuestRunConfig::DEFAULT_SKIP_SHARD_QUESTS,
    ) {}

    /**
     * Runs stored before lists could be chained carry only `quest_list_id`.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $legacyId = (int) ($config['quest_list_id'] ?? 0);

        $ids = is_array($config['quest_list_ids'] ?? null)
            ? array_values(array_map(intval(...), $config['quest_list_ids']))
            : ($legacyId > 0 ? [$legacyId] : []);

        return new self(
            questListIds: $ids,
            stopRage: (int) ($config['stop_rage'] ?? 2500),
            levelUp: (bool) ($config['level_up'] ?? false),
            smart: (bool) ($config['smart'] ?? false),
            respawnWaitSeconds: (int) ($config['respawn_wait_seconds'] ?? QuestRunConfig::DEFAULT_RESPAWN_WAIT_SECONDS),
            skipShardQuests: (bool) ($config['skip_shard_quests'] ?? QuestRunConfig::DEFAULT_SKIP_SHARD_QUESTS),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quest_list_ids' => $this->questListIds,
            // Mirror of the first list for clients that predate chaining.
            'quest_list_id' => $this->questListIds[0] ?? null,
            'stop_rage' => $this->stopRage,
            'level_up' => $this->levelUp,
            'smart' => $this->smart,
            'respawn_wait_seconds' => $this->respawnWaitSeconds,
            'skip_shard_quests' => $this->skipShardQuests,
        ];
    }
}
