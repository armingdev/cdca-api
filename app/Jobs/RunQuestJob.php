<?php

namespace App\Jobs;

use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
use App\Models\RunParticipant;
use Closure;

/**
 * One queued job = one character running a single quest to completion.
 */
class RunQuestJob extends RunJob
{
    protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $onBattle,
    ): ParticipantOutcome {
        $config = QuestRunConfig::fromArray($participant->run->config);

        $summary = QuestRunner::forCharacter($character, $config)
            ->run(log: $log, signal: $signal, onBattle: $onBattle);

        $status = match ($summary->endReason) {
            RunEndReason::ExternalStop => RunStatus::Stopped,
            RunEndReason::ExternalPause => RunStatus::Paused,
            RunEndReason::Completed => RunStatus::Completed,
            default => RunStatus::Stopped,
        };

        return new ParticipantOutcome($status, $summary->stopReason);
    }
}
