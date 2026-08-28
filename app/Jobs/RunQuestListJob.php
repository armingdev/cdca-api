<?php

namespace App\Jobs;

use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestListRunSummary;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Game\Quest\QuestListRunner;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParticipant;
use Closure;

/**
 * One queued job = one character running a quest list to completion.
 */
class RunQuestListJob extends RunJob
{
    protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $onBattle,
    ): ParticipantOutcome {
        $config = QuestListRunConfig::fromArray($participant->run->config);
        $startPosition = (int) ($participant->progress['position'] ?? 0);

        $summary = QuestListRunner::forCharacter($character, $config)->run(
            log: $log,
            signal: $signal,
            onBattle: $onBattle,
            startPosition: $startPosition,
            onQuestSettled: function (int $nextPosition, int $completed, int $skipped) use ($participant): void {
                $participant->update(['progress' => array_merge($participant->progress ?? [], [
                    'position' => $nextPosition,
                    'quests_completed' => $completed,
                    'quests_skipped' => $skipped,
                ])]);
            },
        );

        return $this->outcomeForListEnd($summary, $config, $participant->run, $participant->progress ?? [], $character);
    }

    /**
     * Decide what a finished list cycle means for the participant. The parked
     * position stays on the unsettled quest, so a resume re-enters it and
     * re-reads its progress from the game.
     *
     * @param  array<string, mixed>  $progressIn
     */
    public function outcomeForListEnd(
        QuestListRunSummary $summary,
        QuestListRunConfig $config,
        Run $run,
        array $progressIn,
        Character $character,
    ): ParticipantOutcome {
        if ($summary->endReason === RunEndReason::CircumspectExpired
            || ($run->require_circumspect && in_array($summary->endReason, [
                RunEndReason::RageExhausted,
                RunEndReason::RageInsufficient,
            ], true))
        ) {
            return $this->waitForCircumspect($character, $summary->stopReason, [
                'position' => $summary->nextPosition,
                'respawn_waits' => 0,
            ]);
        }

        // The current quest's targets are all dead — park the list where it
        // stands rather than abandoning the remaining quests.
        if ($summary->endReason === RunEndReason::TargetsDepleted) {
            $madeProgress = $summary->kills > 0 || $summary->questsCompleted > 0;
            $waits = $madeProgress ? 1 : (int) ($progressIn['respawn_waits'] ?? 0) + 1;

            return $this->waitForRespawn($summary->stopReason, $config->respawnWaitSeconds, [
                'position' => $summary->nextPosition,
                'respawn_waits' => $waits,
            ]);
        }

        // Rage the character cannot rebuild by playing: park the list where it
        // stands until the game's hourly tick tops it up.
        if ($summary->endReason === RunEndReason::RageInsufficient
            || $summary->endReason === RunEndReason::RageExhausted
        ) {
            $madeProgress = $summary->kills > 0 || $summary->questsCompleted > 0;

            return $this->waitForRage($summary->stopReason, [
                'position' => $summary->nextPosition,
                'rage_waits' => $this->rageWaits($progressIn, $madeProgress),
                'respawn_waits' => 0,
            ]);
        }

        $status = match ($summary->endReason) {
            RunEndReason::ExternalStop => RunStatus::Stopped,
            RunEndReason::ExternalPause => RunStatus::Paused,
            RunEndReason::Completed => RunStatus::Completed,
            // Terminal by design: parking would only burn the same rage on the
            // same unwinnable fight later.
            RunEndReason::Outmatched => RunStatus::Stopped,
            default => RunStatus::Stopped,
        };

        return new ParticipantOutcome($status, $summary->stopReason, progress: [
            'position' => $summary->nextPosition,
            'respawn_waits' => 0,
        ]);
    }
}
