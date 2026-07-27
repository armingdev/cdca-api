<?php

namespace App\Jobs;

use App\Game\Combat\PvpRunner;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\PvpRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\RunParticipant;
use Closure;

/**
 * One queued job = one character running a PvP target list.
 */
class RunPvpJob extends RunJob
{
    protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $onBattle,
    ): ParticipantOutcome {
        $config = PvpRunConfig::fromArray($participant->run->config);

        $summary = PvpRunner::forCharacter($character, $config)
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
