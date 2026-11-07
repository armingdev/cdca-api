<?php

namespace App\Game\Engine;

/**
 * Where a quest-list run stands, in the shape it survives a park in: the
 * participant's progress json. A run is parked and re-driven many times (rage,
 * respawns, Circumspect), so everything the next job needs to carry on — the
 * cursor, the tallies, which quests wait for a later pass — lives here, and
 * the tallies accumulate across every job rather than restarting at zero.
 *
 * Pass 1 plays the lists in full. A quest the character is not ready for (too
 * low a level, or a prerequisite that is itself waiting) is deferred rather
 * than walked to; when a pass ends and the character has since grown into one
 * of them, another pass replays the lists running only the deferred quests.
 */
final readonly class QuestListProgress
{
    public const string DEFERRED_LEVEL = 'level_too_low';

    public const string DEFERRED_PREREQUISITE = 'prerequisite_pending';

    /**
     * @param  array<string, int>  $skippedByReason  skip reason => how many quests it cost
     * @param  array<int, string>  $deferred  quest id => why it waits (a DEFERRED_* reason); a set — jsonb keeps no key order
     * @param  list<int>|null  $retrying  quest ids this re-pass replays; null on the first pass
     */
    public function __construct(
        public int $listIndex = 0,
        public int $position = 0,
        /** Parked retries already spent on the quest at $position. */
        public int $questRetries = 0,
        public int $completed = 0,
        public int $skipped = 0,
        public array $skippedByReason = [],
        public array $deferred = [],
        public int $pass = 1,
        public ?array $retrying = null,
    ) {}

    /**
     * @param  array<string, mixed>  $progress  a participant's progress json
     */
    public static function fromArray(array $progress): self
    {
        $deferred = [];

        foreach ((array) ($progress['deferred'] ?? []) as $questId => $reason) {
            $deferred[(int) $questId] = (string) $reason;
        }

        return new self(
            // Runs from before list chaining stored only a position: list 0.
            listIndex: (int) ($progress['list_index'] ?? 0),
            position: (int) ($progress['position'] ?? 0),
            questRetries: (int) ($progress['quest_retries'] ?? 0),
            completed: (int) ($progress['quests_completed'] ?? 0),
            skipped: (int) ($progress['quests_skipped'] ?? 0),
            skippedByReason: array_map(intval(...), (array) ($progress['skipped_by_reason'] ?? [])),
            deferred: $deferred,
            pass: max(1, (int) ($progress['pass'] ?? 1)),
            retrying: is_array($progress['retrying'] ?? null) ? array_values(array_map(intval(...), $progress['retrying'])) : null,
        );
    }

    /**
     * @return array{list_index: int, position: int, quest_retries: int, quests_completed: int, quests_skipped: int, quests_deferred: int, skipped_by_reason: array<string, int>, deferred: array<int, string>, pass: int, retrying: list<int>|null}
     */
    public function toArray(): array
    {
        return [
            'list_index' => $this->listIndex,
            'position' => $this->position,
            'quest_retries' => $this->questRetries,
            'quests_completed' => $this->completed,
            'quests_skipped' => $this->skipped,
            'quests_deferred' => count($this->deferred),
            'skipped_by_reason' => $this->skippedByReason,
            'deferred' => $this->deferred,
            'pass' => $this->pass,
            'retrying' => $this->retrying,
        ];
    }
}
