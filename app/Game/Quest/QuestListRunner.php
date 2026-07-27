<?php

namespace App\Game\Quest;

use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestListRunSummary;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunSignal;
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
 * A start position lets a paused or rage-parked participant resume mid-list;
 * the settle callback reports each processed item so the caller can persist
 * that position.
 */
class QuestListRunner
{
    private int $completed = 0;

    private int $skipped = 0;

    private int $kills = 0;

    /** The list position the next cycle should start from. */
    private int $nextPosition = 0;

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
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  int  $startPosition  skip list items below this position (resume support)
     * @param  Closure(int, int, int): void|null  $onQuestSettled  (nextPosition, completed, skipped) after each settled item
     */
    public function run(
        ?Closure $log = null,
        ?Closure $signal = null,
        ?Closure $onBattle = null,
        int $startPosition = 0,
        ?Closure $onQuestSettled = null,
    ): QuestListRunSummary {
        $log ??= fn (string $message) => null;

        $list = QuestList::with('items.quest')->find($this->config->questListId);

        if ($list === null) {
            throw new GameException("Quest list #{$this->config->questListId} not found.");
        }

        $items = $list->items->where('position', '>=', $startPosition)->values();
        $this->nextPosition = $startPosition;

        $log(sprintf(
            "Running quest list '%s' (%d quest(s)%s).",
            $list->name,
            $items->count(),
            $startPosition > 0 ? ", resuming from position {$startPosition}" : '',
        ));

        foreach ($items as $item) {
            $this->nextPosition = $item->position;

            $control = $signal !== null ? $signal() : RunSignal::None;

            if ($control === RunSignal::Stop) {
                return $this->summary(completed: false, reason: 'Stop requested.', endReason: RunEndReason::ExternalStop);
            }

            if ($control === RunSignal::Pause) {
                return $this->summary(completed: false, reason: 'Pause requested.', endReason: RunEndReason::ExternalPause);
            }

            $outcome = $this->runQuest($item, $log, $signal, $onBattle);

            if ($outcome !== null) {
                return $outcome;
            }

            $this->nextPosition = $item->position + 1;
            $onQuestSettled?->__invoke($this->nextPosition, $this->completed, $this->skipped);
        }

        return $this->summary(completed: true, reason: 'Quest list complete.', endReason: RunEndReason::Completed);
    }

    /**
     * Run one list item. Returns a terminal summary when the list should stop,
     * or null to continue to the next quest.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    private function runQuest(QuestListItem $item, Closure $log, ?Closure $signal, ?Closure $onBattle): ?QuestListRunSummary
    {
        $quest = $item->quest;

        if ($quest->giver === null) {
            return $this->summary(
                completed: false,
                reason: "Stopped on {$item->displayName()}: quest {$quest->game_quest_id} has no known giver.",
                endReason: RunEndReason::Stuck,
            );
        }

        $log("→ {$item->displayName()} (quest {$quest->game_quest_id} via {$quest->giver}).");

        $questConfig = new QuestRunConfig(
            npcName: $quest->giver,
            questId: $quest->game_quest_id,
            stopRage: $this->config->stopRage,
            levelUp: $this->config->levelUp,
        );

        try {
            $summary = QuestRunner::forCharacter($this->character, $questConfig)
                ->run(log: $log, signal: $signal, onBattle: $onBattle);
        } catch (QuestNotAvailableException) {
            $this->skipped++;
            $log("Already completed — skipping {$item->displayName()}.");

            return null;
        }

        $this->kills += $summary->kills;

        if ($summary->endReason === RunEndReason::ExternalStop) {
            return $this->summary(completed: false, reason: 'Stop requested.', endReason: RunEndReason::ExternalStop);
        }

        if ($summary->endReason === RunEndReason::ExternalPause) {
            return $this->summary(completed: false, reason: 'Pause requested.', endReason: RunEndReason::ExternalPause);
        }

        if (! $summary->completed) {
            return $this->summary(
                completed: false,
                reason: "Stopped on {$item->displayName()}: {$summary->stopReason}",
                endReason: $summary->endReason,
            );
        }

        $this->completed++;

        return null;
    }

    private function summary(bool $completed, string $reason, RunEndReason $endReason): QuestListRunSummary
    {
        return new QuestListRunSummary(
            completed: $completed,
            questsCompleted: $this->completed,
            questsSkipped: $this->skipped,
            kills: $this->kills,
            stopReason: $reason,
            endReason: $endReason,
            nextPosition: $this->nextPosition,
        );
    }
}
