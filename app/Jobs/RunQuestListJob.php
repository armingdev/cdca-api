<?php

namespace App\Jobs;

use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Game\Quest\QuestListRunner;
use App\Models\Character;
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

        if ($summary->endReason === RunEndReason::RageExhausted && $participant->run->require_circumspect) {
            return $this->waitForCircumspect($character, $summary->stopReason, [
                'position' => $summary->nextPosition,
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
        ]);
    }
}
