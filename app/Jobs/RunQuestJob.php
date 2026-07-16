<?php

namespace App\Jobs;

use App\Game\Engine\QuestRunConfig;
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
        Closure $shouldStop,
        Closure $onBattle,
    ): array {
        $config = QuestRunConfig::fromArray($participant->run->config);

        $summary = QuestRunner::forCharacter($character, $config)
            ->run(log: $log, shouldStop: $shouldStop, onBattle: $onBattle);

        $status = match (true) {
            $summary->externallyStopped => RunStatus::Stopped,
            $summary->completed => RunStatus::Completed,
            default => RunStatus::Failed,
        };

        return [$status, $summary->stopReason];
    }
}
