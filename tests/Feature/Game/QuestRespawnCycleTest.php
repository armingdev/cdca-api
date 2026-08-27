<?php

use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestListRunSummary;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\QuestRunSummary;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Jobs\RunQuestJob;
use App\Jobs\RunQuestListJob;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

/**
 * @param  array<string, mixed>  $configOverrides
 * @param  array<string, mixed>  $progress
 */
function questEndOutcome(
    RunEndReason $endReason,
    array $configOverrides = [],
    array $progress = [],
    int $kills = 0,
    int $stepsCompleted = 0,
): ParticipantOutcome {
    $config = QuestRunConfig::fromArray(array_merge(
        ['npc_name' => 'Stella', 'quest_id' => 742],
        $configOverrides,
    ));
    $run = Run::factory()->state([
        'mode' => RunMode::Quest,
        'config' => $config->toArray(),
        'status' => RunStatus::Running,
    ])->create();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    $summary = new QuestRunSummary(
        completed: false,
        stepsCompleted: $stepsCompleted,
        expGained: 0,
        kills: $kills,
        stopReason: "All 'Street Crawler' targets are dead — waiting for respawn.",
        endReason: $endReason,
    );

    return new RunQuestJob($participant, (string) Str::uuid())
        ->outcomeForQuestEnd($summary, $config, $run, $progress, $character);
}

/**
 * @param  array<string, mixed>  $progress
 */
function questListEndOutcome(
    RunEndReason $endReason,
    array $progress = [],
    int $kills = 0,
    int $nextPosition = 3,
): ParticipantOutcome {
    $config = QuestListRunConfig::fromArray(['quest_list_id' => 1]);
    $run = Run::factory()->state([
        'mode' => RunMode::QuestList,
        'config' => $config->toArray(),
        'status' => RunStatus::Running,
    ])->create();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    $summary = new QuestListRunSummary(
        completed: false,
        questsCompleted: 0,
        questsSkipped: 0,
        kills: $kills,
        stopReason: "Waiting on Quest 742: All 'Street Crawler' targets are dead — waiting for respawn.",
        endReason: $endReason,
        nextPosition: $nextPosition,
    );

    return new RunQuestListJob($participant, (string) Str::uuid())
        ->outcomeForListEnd($summary, $config, $run, $progress, $character);
}

it('parks a depleted quest until the default respawn wait elapses', function () {
    $this->freezeTime();

    $outcome = questEndOutcome(RunEndReason::TargetsDepleted);

    expect($outcome->status)->toBe(RunStatus::Waiting)
        ->and($outcome->resumeAt->timestamp)->toBe(now()->addSeconds(60)->timestamp)
        ->and($outcome->progress)->toMatchArray(['respawn_waits' => 1])
        ->and($outcome->reason)->toContain('waiting for respawn')
        ->and($outcome->reason)->toContain('Resumes');
});

it('honors a custom respawn wait', function () {
    $this->freezeTime();

    $outcome = questEndOutcome(RunEndReason::TargetsDepleted, ['respawn_wait_seconds' => 300]);

    expect($outcome->status)->toBe(RunStatus::Waiting)
        ->and($outcome->resumeAt->timestamp)->toBe(now()->addSeconds(300)->timestamp);
});

it('counts consecutive barren waits and resets the tally once a cycle makes progress', function () {
    $barren = questEndOutcome(RunEndReason::TargetsDepleted, progress: ['respawn_waits' => 4]);
    $killed = questEndOutcome(RunEndReason::TargetsDepleted, progress: ['respawn_waits' => 4], kills: 2);
    $stepped = questEndOutcome(RunEndReason::TargetsDepleted, progress: ['respawn_waits' => 4], stepsCompleted: 1);

    expect($barren->progress)->toMatchArray(['respawn_waits' => 5])
        ->and($killed->progress)->toMatchArray(['respawn_waits' => 1])
        ->and($stepped->progress)->toMatchArray(['respawn_waits' => 1]);
});

it('gives up once nothing has respawned for the whole barren budget', function () {
    $lastWait = questEndOutcome(RunEndReason::TargetsDepleted, progress: ['respawn_waits' => 29]);
    $exhausted = questEndOutcome(RunEndReason::TargetsDepleted, progress: ['respawn_waits' => 30]);

    expect($lastWait->status)->toBe(RunStatus::Waiting)
        ->and($exhausted->status)->toBe(RunStatus::Stopped)
        ->and($exhausted->reason)->toContain('Nothing respawned after 30 waits')
        ->and($exhausted->resumeAt)->toBeNull();
});

it('clears the barren tally on every terminal quest outcome', function () {
    $completed = questEndOutcome(RunEndReason::Completed, progress: ['respawn_waits' => 7]);
    $stuck = questEndOutcome(RunEndReason::Stuck, progress: ['respawn_waits' => 7]);

    expect($completed->status)->toBe(RunStatus::Completed)
        ->and($completed->progress)->toMatchArray(['respawn_waits' => 0])
        ->and($stuck->status)->toBe(RunStatus::Stopped)
        ->and($stuck->progress)->toMatchArray(['respawn_waits' => 0]);
});

it('parks a quest and a quest list until Circumspect can be recast', function () {
    seedCircumspect();

    $quest = questEndOutcome(RunEndReason::CircumspectExpired, progress: ['respawn_waits' => 4]);
    $list = questListEndOutcome(RunEndReason::CircumspectExpired, progress: ['position' => 3]);

    expect($quest->status)->toBe(RunStatus::Waiting)
        ->and($quest->reason)->toContain('Waiting for Circumspect')
        ->and($quest->progress)->toMatchArray(['respawn_waits' => 0])
        ->and($list->status)->toBe(RunStatus::Waiting)
        ->and($list->progress)->toMatchArray(['position' => 3]);
});

it('keeps a stuck quest terminal rather than waiting on it', function () {
    $outcome = questEndOutcome(RunEndReason::Stuck);

    expect($outcome->status)->toBe(RunStatus::Stopped)
        ->and($outcome->resumeAt)->toBeNull();
});

it('parks a depleted quest list on the unsettled position', function () {
    $this->freezeTime();

    $outcome = questListEndOutcome(RunEndReason::TargetsDepleted, progress: ['position' => 3, 'respawn_waits' => 2]);

    expect($outcome->status)->toBe(RunStatus::Waiting)
        ->and($outcome->resumeAt->timestamp)->toBe(now()->addSeconds(60)->timestamp)
        ->and($outcome->progress)->toMatchArray(['position' => 3, 'respawn_waits' => 3]);
});

it('gives up on a quest list whose targets never come back', function () {
    $outcome = questListEndOutcome(RunEndReason::TargetsDepleted, progress: ['position' => 3, 'respawn_waits' => 30]);

    expect($outcome->status)->toBe(RunStatus::Stopped)
        ->and($outcome->progress)->toMatchArray(['position' => 3])
        ->and($outcome->reason)->toContain('Nothing respawned after 30 waits');
});
