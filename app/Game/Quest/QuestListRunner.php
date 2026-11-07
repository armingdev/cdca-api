<?php

namespace App\Game\Quest;

use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestListRunSummary;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\RunEventType;
use App\Game\Enums\RunSignal;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\LoginFailedException;
use App\Game\Exceptions\ParseException;
use App\Game\Exceptions\QuestNotAvailableException;
use App\Game\Exceptions\SessionCollisionException;
use App\Game\Exceptions\TransientGameException;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\QuestList;
use App\Models\QuestListItem;
use App\Models\RunEvent;
use Closure;

/**
 * Runs a run's quest lists back to back, each in order: for each quest, run
 * it via QuestRunner.
 *
 * A quest the list cannot finish is skipped, not fatal — already completed,
 * giver unknown or unreachable, objective unfulfillable, or wanting an item
 * the game only sells. One bad entry at position 3 must never cost the
 * remaining thirty-seven. Only a whole-list condition ends the run: a stop or
 * pause, a lapsed Circumspect buff, or rage the character cannot rebuild
 * without waiting — and the last three park rather than stop.
 *
 * A start list index + position lets a paused or parked participant resume
 * mid-list; the settle callback reports each processed item (and each list
 * finished) so the caller can persist that cursor.
 */
class QuestListRunner
{
    /**
     * Parked retries one quest may cost the list before it is written off. A
     * page that would not parse, or an NPC not standing in its room this
     * minute, used to read as "skip this quest" — permanently, for the whole
     * run. Two retries separate a blip from a real dead end.
     */
    public const int MAX_QUEST_RETRIES = 2;

    private int $completed = 0;

    private int $skipped = 0;

    private int $kills = 0;

    /** The list position the next cycle should start from. */
    private int $nextPosition = 0;

    /** Which of the run's lists $nextPosition belongs to. */
    private int $listIndex = 0;

    /** The list being played — tags skip/complete events, whose positions are per list. */
    private ?int $currentListId = null;

    /** Parked retries already spent on the quest at $nextPosition. */
    private int $questRetries = 0;

    private ?RunEventRecorder $events = null;

    private ?int $runId = null;

    /** Quest ids this character has already settled, skipped without walking. */
    /** @var list<int> */
    private array $skippableQuestIds = [];

    public function __construct(
        private readonly Character $character,
        private readonly QuestListRunConfig $config,
        private readonly QuestProgressLedger $ledger,
    ) {}

    public static function forCharacter(Character $character, QuestListRunConfig $config): self
    {
        return new self($character, $config, app(QuestProgressLedger::class));
    }

    /**
     * Play the run's lists back to back, starting at ($startListIndex,
     * $startPosition). Quests completed in an earlier list are recorded in
     * the ledger as they finish, so a later list that repeats them skips
     * them like any other settled quest.
     *
     * @param  Closure(string): void|null  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs  just-in-time buff top-up, forwarded to each quest
     * @param  int  $startPosition  skip items below this position in the start list (resume support)
     * @param  Closure(int, int, int, int): void|null  $onQuestSettled  (nextPosition, completed, skipped, listIndex) after each settled item and each finished list
     * @param  RunEventRecorder|null  $events  durable log for skip/complete decisions
     * @param  int  $questRetries  parked retries already spent on the quest at $startPosition
     * @param  int|null  $runId  provenance for anything written to the progress ledger
     * @param  int  $startListIndex  which of the run's lists to resume in (0-based)
     */
    public function run(
        ?Closure $log = null,
        ?Closure $signal = null,
        ?Closure $onBattle = null,
        ?Closure $ensureBuffs = null,
        int $startPosition = 0,
        ?Closure $onQuestSettled = null,
        ?RunEventRecorder $events = null,
        int $questRetries = 0,
        ?int $runId = null,
        int $startListIndex = 0,
    ): QuestListRunSummary {
        $this->questRetries = $questRetries;
        $this->events = $events;
        $this->runId = $runId;
        $log ??= fn (string $message) => null;

        $listIds = $this->config->questListIds;
        $lists = QuestList::with('items.quest')->whereIn('id', $listIds)->get()->keyBy('id');

        if ($lists->isEmpty()) {
            throw new GameException('Quest list #'.implode(', #', $listIds).' not found.');
        }

        for ($index = $startListIndex; $index < count($listIds); $index++) {
            $this->listIndex = $index;
            $position = $index === $startListIndex ? $startPosition : 0;
            $list = $lists->get($listIds[$index]);

            if ($list === null) {
                // Deleted after the run started: the remaining lists still run.
                $log("Quest list #{$listIds[$index]} no longer exists — skipping it.");
                $events?->record(RunEventType::Info, "Quest list #{$listIds[$index]} no longer exists — skipped.", [
                    'quest_list_id' => $listIds[$index],
                    'list_index' => $index,
                ], RunEvent::LEVEL_WARNING);
            } else {
                $outcome = $this->runList($list, $index, count($listIds), $position, $log, $signal, $onBattle, $ensureBuffs, $onQuestSettled);

                if ($outcome !== null) {
                    return $outcome;
                }
            }

            // Persist the move to the next list, so a resume never re-enters
            // the tail of a list that is already done. After the last list
            // the cursor stays past its end, as a single-list run always had.
            if ($index + 1 < count($listIds)) {
                $this->listIndex = $index + 1;
                $this->nextPosition = 0;
                $onQuestSettled?->__invoke($this->nextPosition, $this->completed, $this->skipped, $this->listIndex);
            }
        }

        return $this->summary(
            completed: true,
            reason: count($listIds) > 1 ? 'All quest lists complete.' : 'Quest list complete.',
            endReason: RunEndReason::Completed,
        );
    }

    /**
     * One list of the run, from $startPosition. Null when it ran to its end;
     * a summary when the whole run has to stop or park on one of its quests.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     * @param  Closure(int, int, int, int): void|null  $onQuestSettled
     */
    private function runList(
        QuestList $list,
        int $index,
        int $listCount,
        int $startPosition,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs,
        ?Closure $onQuestSettled,
    ): ?QuestListRunSummary {
        $this->currentListId = $list->id;
        $items = $list->items->where('position', '>=', $startPosition)->values();
        $this->nextPosition = $startPosition;

        // Read once per list, before walking anywhere: what this character has
        // already settled with the game. Re-deriving it by visiting all 200
        // givers is exactly the cost this avoids.
        $this->skippableQuestIds = $this->ledger->skippableQuestIds(
            $this->character,
            $items->pluck('quest_id'),
        );

        $message = sprintf(
            "Running quest list '%s'%s (%d quest(s)%s).",
            $list->name,
            $listCount > 1 ? ' — list '.($index + 1)." of {$listCount}" : '',
            $items->count(),
            $startPosition > 0 ? ", resuming from position {$startPosition}" : '',
        );
        $log($message);

        if ($listCount > 1) {
            $this->events?->record(RunEventType::Info, $message, [
                'quest_list_id' => $list->id,
                'list_index' => $index,
                'list_count' => $listCount,
            ]);
        }

        if ($this->skippableQuestIds !== []) {
            $recorded = count($this->skippableQuestIds);
            $log("Skipping {$recorded} quest(s) already recorded for this character.");
            $this->events?->record(
                RunEventType::QuestSkipped,
                "Skipping {$recorded} quest(s) already recorded for this character.",
                ['reason' => 'recorded', 'count' => $recorded, 'quest_ids' => $this->skippableQuestIds, 'quest_list_id' => $list->id],
            );
        }

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

            if ($control === RunSignal::WorkerShutdown) {
                return $this->summary(completed: false, reason: 'Worker restarting.', endReason: RunEndReason::WorkerShutdown);
            }

            $outcome = $this->runQuest($item, $log, $signal, $onBattle, $ensureBuffs);

            if ($outcome !== null) {
                return $outcome;
            }

            $this->nextPosition = $item->position + 1;
            $onQuestSettled?->__invoke($this->nextPosition, $this->completed, $this->skipped, $this->listIndex);
        }

        return null;
    }

    /**
     * Run one list item. Returns a terminal summary when the list should stop,
     * or null to continue to the next quest.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     */
    private function runQuest(
        QuestListItem $item,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs = null,
    ): ?QuestListRunSummary {
        $quest = $item->quest;

        if (in_array($quest?->id, $this->skippableQuestIds, true)) {
            // Already settled for this character — the summary event above
            // covers the whole batch, so this one stays silent.
            $this->skipped++;

            return null;
        }

        if ($quest->giver === null) {
            $this->skipped++;
            $log("No known giver — skipping {$item->displayName()}.");
            $this->recordSkip($item, 'no_giver', 'No known giver.');

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
                ->run(log: $log, signal: $signal, onBattle: $onBattle, ensureBuffs: $ensureBuffs, events: $this->events);
        } catch (QuestNotAvailableException) {
            // Not in the character's tracker and offered by nobody: either
            // finished long ago or gated behind something unmet. A quest that
            // is merely *under way* never reaches here — QuestRunner raises a
            // plain GameException for that, so no guess lands in the ledger.
            $this->skipped++;
            $log("No mob offers {$item->displayName()} and it is not in progress — skipping.");
            $this->recordSkip($item, 'not_available', 'No mob offers this quest and it is not in progress.');
            // Remember it, so the next run does not walk here to be told the
            // same thing. Clearable, because the giver is equally silent about
            // a quest whose prerequisites are simply not met yet.
            $this->ledger->recordUnavailable($this->character, $quest, $this->runId);

            return null;
        } catch (SessionCollisionException|LoginFailedException $exception) {
            // Session-level failures are the job's to recover from, not a
            // property of this quest — never swallow them into a skip.
            throw $exception;
        } catch (TransientGameException|ParseException $exception) {
            // A page that would not parse, or an NPC that happens not to be
            // standing in its room, says nothing about whether this character
            // can do the quest. Park and come back to it; only a quest that
            // fails this way repeatedly is written off.
            return $this->retryOrSkip($item, $exception->getMessage(), $log);
        } catch (GameException $exception) {
            // An unmapped giver or a world with no path to it is a real
            // property of this quest, and waiting will not change it — but the
            // remaining quests are still perfectly runnable.
            $this->skipped++;
            $log("Skipping {$item->displayName()}: {$exception->getMessage()}");
            $this->recordSkip($item, 'unreachable', $exception->getMessage());

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

        if ($summary->endReason === RunEndReason::WorkerShutdown) {
            return $this->summary(completed: false, reason: $summary->stopReason, endReason: RunEndReason::WorkerShutdown);
        }

        // Nothing the list can do about this quest, and everything it can do
        // about the next one: skip rather than take the whole list down.
        if ($summary->endReason === RunEndReason::RequiresPurchasedItem
            || $summary->endReason === RunEndReason::Stuck
            || $summary->endReason === RunEndReason::Outmatched
        ) {
            $this->skipped++;
            $log("Skipping {$item->displayName()}: {$summary->stopReason}");
            $this->recordSkip($item, $summary->endReason->value, $summary->stopReason);

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
        $this->questRetries = 0;
        $this->ledger->recordCompleted($this->character, $quest, $this->runId);

        $this->events?->record(RunEventType::QuestCompleted, "Completed {$item->displayName()}.", [
            'quest_id' => $quest->game_quest_id,
            'position' => $item->position,
            'quest_list_id' => $this->currentListId,
        ]);

        return null;
    }

    /**
     * A failure that is not the quest's fault: park the list on this item so
     * the same quest is retried, until the retry budget runs out.
     *
     * @param  Closure(string): void  $log
     */
    private function retryOrSkip(QuestListItem $item, string $message, Closure $log): ?QuestListRunSummary
    {
        if ($this->questRetries >= self::MAX_QUEST_RETRIES) {
            $attempts = $this->questRetries;
            $this->skipped++;
            $this->questRetries = 0;
            $log("Skipping {$item->displayName()} after {$attempts} retries: {$message}");
            $this->recordSkip($item, 'transient_exhausted', $message);

            return null;
        }

        $this->questRetries++;

        $this->events?->record(
            RunEventType::QuestRetryScheduled,
            "Retrying {$item->displayName()} (attempt {$this->questRetries}): {$message}",
            [
                'quest_id' => $item->quest->game_quest_id,
                'position' => $item->position,
                'quest_list_id' => $this->currentListId,
                'attempt' => $this->questRetries,
            ],
            RunEvent::LEVEL_WARNING,
        );

        return $this->summary(
            completed: false,
            reason: "Paused on {$item->displayName()}: {$message}",
            endReason: RunEndReason::TransientError,
        );
    }

    private function recordSkip(QuestListItem $item, string $reason, string $message): void
    {
        $this->events?->record(
            RunEventType::QuestSkipped,
            "Skipped {$item->displayName()}: {$message}",
            [
                'quest_id' => $item->quest?->game_quest_id,
                'position' => $item->position,
                'quest_list_id' => $this->currentListId,
                'reason' => $reason,
            ],
            RunEvent::LEVEL_WARNING,
        );
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
            questRetries: $this->questRetries,
            nextListIndex: $this->listIndex,
        );
    }
}
