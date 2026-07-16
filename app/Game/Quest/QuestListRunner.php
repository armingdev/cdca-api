<?php

namespace App\Game\Quest;

use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestListRunSummary;
use App\Game\Engine\QuestRunConfig;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\QuestNotAvailableException;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\QuestList;
use App\Models\QuestListItem;
use Closure;

/**
 * Runs a named quest list in order: for each quest, run it via QuestRunner;
 * a quest that is no longer available at its giver (already completed) is
 * skipped; a quest that gets stuck (rage floor, unfulfillable objective)
 * stops the whole list. When every item is processed, the list is complete.
 */
class QuestListRunner
{
    private int $completed = 0;

    private int $skipped = 0;

    private int $kills = 0;

    public function __construct(
        private readonly Character $character,
        private readonly QuestListRunConfig $config,
    ) {}

    public static function forCharacter(Character $character, QuestListRunConfig $config): self
    {
        return new self($character, $config);
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): bool|null  $shouldStop
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    public function run(?Closure $log = null, ?Closure $shouldStop = null, ?Closure $onBattle = null): QuestListRunSummary
    {
        $log ??= fn (string $message) => null;

        $list = QuestList::with('items')->find($this->config->questListId);

        if ($list === null) {
            throw new GameException("Quest list #{$this->config->questListId} not found.");
        }

        $log("Running quest list '{$list->name}' ({$list->items->count()} quest(s)).");

        foreach ($list->items as $item) {
            if ($shouldStop !== null && $shouldStop()) {
                return $this->summary(completed: false, reason: 'Stop requested.', externallyStopped: true);
            }

            $outcome = $this->runQuest($item, $log, $shouldStop, $onBattle);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        return $this->summary(completed: true, reason: 'Quest list complete.');
    }

    /**
     * Run one list item. Returns a terminal summary when the list should stop,
     * or null to continue to the next quest.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): bool|null  $shouldStop
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    private function runQuest(QuestListItem $item, Closure $log, ?Closure $shouldStop, ?Closure $onBattle): ?QuestListRunSummary
    {
        $log("→ {$item->displayName()} (quest {$item->quest_id} via {$item->npc_name}).");

        $questConfig = new QuestRunConfig(
            npcName: $item->npc_name,
            questId: $item->quest_id,
            stopRage: $this->config->stopRage,
            levelUp: $this->config->levelUp,
        );

        try {
            $summary = QuestRunner::forCharacter($this->character, $questConfig)
                ->run(log: $log, shouldStop: $shouldStop, onBattle: $onBattle);
        } catch (QuestNotAvailableException) {
            $this->skipped++;
            $log("Already completed — skipping {$item->displayName()}.");

            return null;
        }

        $this->kills += $summary->kills;

        if ($summary->externallyStopped) {
            return $this->summary(completed: false, reason: 'Stop requested.', externallyStopped: true);
        }

        if (! $summary->completed) {
            return $this->summary(
                completed: false,
                reason: "Stopped on {$item->displayName()}: {$summary->stopReason}",
            );
        }

        $this->completed++;

        return null;
    }

    private function summary(bool $completed, string $reason, bool $externallyStopped = false): QuestListRunSummary
    {
        return new QuestListRunSummary(
            completed: $completed,
            questsCompleted: $this->completed,
            questsSkipped: $this->skipped,
            kills: $this->kills,
            stopReason: $reason,
            externallyStopped: $externallyStopped,
        );
    }
}
