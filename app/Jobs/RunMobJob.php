<?php

namespace App\Jobs;

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
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
        Closure $shouldStop,
        Closure $onBattle,
    ): array {
        $config = MobRunConfig::fromArray($participant->run->config);

        $summary = MobRunner::forCharacter($character, $config)
            ->run(log: $log, shouldStop: $shouldStop, onBattle: $onBattle);

        return [
            $summary->externallyStopped ? RunStatus::Stopped : RunStatus::Completed,
            $summary->stopReason,
        ];
    }
}
