<?php

use App\Game\Auth\LoginService;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunSummary;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Jobs\RunMobJob;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
});

/**
 * @param  array<string, mixed>  $configOverrides
 * @param  array<string, mixed>  $progress
 */
function passEndOutcome(
    RunEndReason $endReason,
    array $configOverrides = [],
    array $progress = [],
    bool $requireCircumspect = false,
    int $wins = 1,
): ParticipantOutcome {
    $config = MobRunConfig::fromArray(array_merge(['mob_names' => ['Kix Harvester']], $configOverrides));
    $run = Run::factory()->state([
        'config' => $config->toArray(),
        'require_circumspect' => $requireCircumspect,
        'status' => RunStatus::Running,
    ])->create();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    $summary = new MobRunSummary(
        wins: $wins,
        losses: 0,
        errors: 0,
        stopReason: $endReason === RunEndReason::RageExhausted ? 'Rage below the 2500 floor.' : 'No live targets remain in any known room.',
        endReason: $endReason,
    );

    return new RunMobJob($participant, (string) Str::uuid())
        ->outcomeForPassEnd($summary, $config, $run, $progress, $character);
}

it('keeps the classic single pass when no cycling option is set', function () {
    $completed = passEndOutcome(RunEndReason::Completed);
    $ragedOut = passEndOutcome(RunEndReason::RageExhausted);

    expect($completed->status)->toBe(RunStatus::Completed)
        ->and($completed->progress)->toMatchArray(['kills_done' => 1, 'cycles_done' => 1])
        ->and($ragedOut->status)->toBe(RunStatus::Completed)
        ->and($ragedOut->reason)->toContain('Rage below');
});

it('waits out the attack interval between passes', function () {
    $this->freezeTime();

    $outcome = passEndOutcome(RunEndReason::Completed, ['attack_interval_seconds' => 300]);

    expect($outcome->status)->toBe(RunStatus::Waiting)
        ->and($outcome->resumeAt->diffInSeconds(now()->addSeconds(300), true))->toBeLessThan(2)
        ->and($outcome->reason)->toContain('Pass 1 complete');
});

it('waits at least a minute between passes when run_count cycles without an interval', function () {
    $this->freezeTime();

    $outcome = passEndOutcome(RunEndReason::Completed, ['run_count' => 3]);

    expect($outcome->status)->toBe(RunStatus::Waiting)
        ->and($outcome->resumeAt->diffInSeconds(now()->addSeconds(60), true))->toBeLessThan(2)
        ->and($outcome->progress['cycles_done'])->toBe(1);
});

it('completes once the configured pass count is reached', function () {
    $outcome = passEndOutcome(
        RunEndReason::Completed,
        ['run_count' => 2, 'attack_interval_seconds' => 300],
        progress: ['cycles_done' => 1, 'kills_done' => 4],
    );

    expect($outcome->status)->toBe(RunStatus::Completed)
        ->and($outcome->reason)->toContain('Reached 2 pass(es)')
        ->and($outcome->progress)->toMatchArray(['cycles_done' => 2, 'kills_done' => 5]);
});

it('lets max_kills end a cycling run early', function () {
    $outcome = passEndOutcome(
        RunEndReason::TargetReached,
        ['run_count' => 5, 'attack_interval_seconds' => 300, 'max_kills' => 10],
    );

    expect($outcome->status)->toBe(RunStatus::Completed);
});

it('waits for rage to regenerate between passes when no circ clock exists', function () {
    $this->freezeTime();

    $short = passEndOutcome(RunEndReason::RageExhausted, ['run_count' => 3, 'attack_interval_seconds' => 300]);
    $long = passEndOutcome(RunEndReason::RageExhausted, ['run_count' => 3, 'attack_interval_seconds' => 3600]);

    // The 30-minute rage floor wins over a shorter interval; a longer interval wins over the floor.
    expect($short->status)->toBe(RunStatus::Waiting)
        ->and($short->resumeAt->diffInSeconds(now()->addSeconds(1800), true))->toBeLessThan(2)
        ->and($long->resumeAt->diffInSeconds(now()->addSeconds(3600), true))->toBeLessThan(2);
});

it('cycles a run_count-bounded farm end to end through the resume scheduler', function () {
    Queue::fake();
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state([
        'config' => ['mob_names' => ['Kix Harvester'], 'run_count' => 2, 'attack_interval_seconds' => 300],
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->progress)->toMatchArray(['kills_done' => 1, 'cycles_done' => 1])
        ->and($run->fresh()->status)->toBe(RunStatus::Waiting);

    $this->travelTo($participant->resume_at->addMinute());

    $this->artisan('outwar:runs-resume-due')->assertSuccessful();
    Queue::assertPushed(RunMobJob::class, 1);

    makeRunJob($participant->fresh())->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->last_activity)->toContain('Reached 2 pass(es)')
        ->and($participant->progress['cycles_done'])->toBe(2)
        ->and($run->fresh()->status)->toBe(RunStatus::Completed);
});
