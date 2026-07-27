<?php

namespace App\Jobs;

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\RunParticipant;
use Closure;

/**
 * One queued job = one character's mob run.
 */
class RunMobJob extends RunJob
{
    protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $onBattle,
    ): ParticipantOutcome {
        $config = MobRunConfig::fromArray($participant->run->config);
        $killsDone = (int) ($participant->progress['kills_done'] ?? 0);

        $summary = MobRunner::forCharacter($character, $config)
            ->run(log: $log, signal: $signal, onBattle: $onBattle, killsAlreadyDone: $killsDone);

        $progress = ['kills_done' => $killsDone + $summary->wins];

        if ($summary->endReason === RunEndReason::RageExhausted && $participant->run->require_circumspect) {
            return $this->waitForCircumspect($character, $summary->stopReason, $progress);
        }

        $status = match ($summary->endReason) {
            RunEndReason::ExternalStop => RunStatus::Stopped,
            RunEndReason::ExternalPause => RunStatus::Paused,
            default => RunStatus::Completed,
        };

        return new ParticipantOutcome($status, $summary->stopReason, progress: $progress);
    }
}
