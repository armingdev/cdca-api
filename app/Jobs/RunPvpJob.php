<?php

namespace App\Jobs;

use App\Game\Combat\PvpRunner;
use App\Game\Combat\Targets\PvpTargetSource;
use App\Game\Combat\Targets\PvpTargetSourceFactory;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\PvpRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\RunParticipant;
use Closure;

/**
 * One queued job = one character running a PvP pass.
 *
 * All list-based PvP modes share this job; they differ only in the target
 * source, which the factory picks from the run mode.
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
        $source = $this->targetSource($character, $participant, $config);

        if ($source === null) {
            return new ParticipantOutcome(RunStatus::Completed, $this->skipReason());
        }

        $summary = PvpRunner::forCharacter($character, $config, $source)
            ->run(log: $log, signal: $signal, onBattle: $onBattle);

        if ($summary->endReason === RunEndReason::CircumspectExpired) {
            return $this->waitForCircumspect($character, $summary->stopReason);
        }

        $status = match ($summary->endReason) {
            RunEndReason::ExternalStop => RunStatus::Stopped,
            RunEndReason::ExternalPause => RunStatus::Paused,
            RunEndReason::Completed, RunEndReason::TargetReached => RunStatus::Completed,
            default => RunStatus::Stopped,
        };

        return new ParticipantOutcome($status, $summary->stopReason);
    }

    /**
     * The source for this pass, or null to skip the pass entirely (brawl
     * modes use this when the window is closed).
     */
    protected function targetSource(
        Character $character,
        RunParticipant $participant,
        PvpRunConfig $config,
    ): ?PvpTargetSource {
        return PvpTargetSourceFactory::for($character, $participant->run->mode, $config);
    }

    /** Why the pass was skipped, when targetSource() returned null. */
    protected function skipReason(): string
    {
        return 'Nothing to do this pass.';
    }
}
