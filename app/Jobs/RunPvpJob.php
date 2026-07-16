<?php

namespace App\Jobs;

use App\Game\Combat\PvpRunner;
use App\Game\Engine\PvpRunConfig;
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
        Closure $shouldStop,
        Closure $onBattle,
    ): array {
        $config = PvpRunConfig::fromArray($participant->run->config);

        $summary = PvpRunner::forCharacter($character, $config)
            ->run(log: $log, shouldStop: $shouldStop, onBattle: $onBattle);

        $status = match (true) {
            $summary->externallyStopped => RunStatus::Stopped,
            $summary->completed => RunStatus::Completed,
            default => RunStatus::Failed,
        };

        return [$status, $summary->stopReason];
    }
}
