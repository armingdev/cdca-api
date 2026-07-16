<?php

namespace App\Jobs;

use App\Game\Engine\QuestListRunConfig;
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
        Closure $shouldStop,
        Closure $onBattle,
    ): array {
        $config = QuestListRunConfig::fromArray($participant->run->config);

        $summary = QuestListRunner::forCharacter($character, $config)
            ->run(log: $log, shouldStop: $shouldStop, onBattle: $onBattle);

        $status = match (true) {
            $summary->externallyStopped => RunStatus::Stopped,
            $summary->completed => RunStatus::Completed,
            default => RunStatus::Failed,
        };

        return [$status, $summary->stopReason];
    }
}
