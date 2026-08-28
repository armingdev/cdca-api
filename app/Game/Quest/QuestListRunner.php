<?php

namespace App\Game\Quest;

use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestListRunSummary;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunSignal;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\LoginFailedException;
use App\Game\Exceptions\QuestNotAvailableException;
use App\Game\Exceptions\SessionCollisionException;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\QuestList;
use App\Models\QuestListItem;
use Closure;

/**
 * Runs a named quest list in order: for each quest, run it via QuestRunner.
 *
 * A quest the list cannot finish is skipped, not fatal — already completed,
 * giver unknown or unreachable, objective unfulfillable, or wanting an item
 * the game only sells. One bad entry at position 3 must never cost the
 * remaining thirty-seven. Only a whole-list condition ends the run: a stop or
 * pause, a lapsed Circumspect buff, or rage the character cannot rebuild
 * without waiting — and the last three park rather than stop.
 *
 * A start position lets a paused or parked participant resume mid-list; the
 * settle callback reports each processed item so the caller can persist that
 * position.
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

            if ($control === RunSignal::CircumspectExpired) {
                return $this->summary(completed: false, reason: 'Circumspect expired.', endReason: RunEndReason::CircumspectExpired);
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
            $this->skipped++;
            $log("No known giver — skipping {$item->displayName()}.");

            return null;
        }

        $log("→ {$item->displayName()} (quest {$quest->game_quest_id} via {$quest->giver}).");

        $questConfig = new QuestRunConfig(
            npcName: $quest->giver,
            questId: $quest->game_quest_id,
            stopRage: $this->config->stopRage,
            levelUp: $this->config->levelUp,
            smart: $this->config->smart,
            respawnWaitSeconds: $this->config->respawnWaitSeconds,
            skipShardQuests: $this->config->skipShardQuests,
        );

        try {
            $summary = QuestRunner::forCharacter($this->character, $questConfig)
                ->run(log: $log, signal: $signal, onBattle: $onBattle);
        } catch (QuestNotAvailableException) {
            $this->skipped++;
            $log("Already completed — skipping {$item->displayName()}.");

            return null;
        } catch (SessionCollisionException|LoginFailedException $exception) {
            // Session-level failures are the job's to recover from, not a
            // property of this quest — never swallow them into a skip.
            throw $exception;
        } catch (GameException $exception) {
            // An unreachable giver or an unmapped room is this quest's problem,
            // not the list's — the remaining quests are still runnable.
            $this->skipped++;
            $log("Skipping {$item->displayName()}: {$exception->getMessage()}");

            return null;
        }

        $this->kills += $summary->kills;

        if ($summary->endReason === RunEndReason::ExternalStop) {
            return $this->summary(completed: false, reason: 'Stop requested.', endReason: RunEndReason::ExternalStop);
        }

        if ($summary->endReason === RunEndReason::ExternalPause) {
            return $this->summary(completed: false, reason: 'Pause requested.', endReason: RunEndReason::ExternalPause);
        }

        if ($summary->endReason === RunEndReason::CircumspectExpired) {
            return $this->summary(completed: false, reason: $summary->stopReason, endReason: RunEndReason::CircumspectExpired);
        }

        // Nothing the list can do about this quest, and everything it can do
        // about the next one: skip rather than take the whole list down.
        if ($summary->endReason === RunEndReason::RequiresPurchasedItem
            || $summary->endReason === RunEndReason::Stuck
            || $summary->endReason === RunEndReason::Outmatched
        ) {
            $this->skipped++;
            $log("Skipping {$item->displayName()}: {$summary->stopReason}");

            return null;
        }

        if (! $summary->completed) {
            // A parked quest resumes where it stands, so the wrapper must not
            // read "Stopped".
            $verb = in_array($summary->endReason, [
                RunEndReason::TargetsDepleted,
                RunEndReason::RageInsufficient,
                RunEndReason::RageExhausted,
            ], true) ? 'Waiting' : 'Stopped';

            return $this->summary(
                completed: false,
                reason: "{$verb} on {$item->displayName()}: {$summary->stopReason}",
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
