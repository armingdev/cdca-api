<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunSignal;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Mob;
use App\Models\QuestList;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
});

it('parks a running participant as paused when a pause signal is set', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    $run->signal(RunSignal::Pause);

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Paused)
        ->and($participant->last_activity)->toContain('Pause requested')
        ->and($participant->finished_at)->toBeNull()
        ->and($participant->progress)->toHaveKey('kills_done')
        ->and($run->fresh()->status)->toBe(RunStatus::Paused);
});

it('stops a running participant when a stop signal is set', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    $run->signal(RunSignal::Stop);

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($participant->fresh()->finished_at)->not->toBeNull()
        ->and($run->fresh()->status)->toBe(RunStatus::Stopped);
});

it('counts kills from earlier cycles against max_kills when resuming', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state([
        'config' => ['mob_names' => ['Kix Harvester'], 'max_kills' => 4],
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create([
        'progress' => ['kills_done' => 3],
    ]);

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->last_activity)->toContain('Reached 4 kills')
        ->and($participant->progress['kills_done'])->toBe(4);
});

it('reports quest rage-out as stopped, not failed', function () {
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld(rage: 100);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::Quest,
            'config' => ['npc_name' => 'Stella', 'quest_id' => 742, 'stop_rage' => 2500],
            'status' => RunStatus::Running,
        ]))
        ->for($character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($participant->fresh()->last_activity)->toContain('Rage below')
        ->and($participant->run->fresh()->status)->toBe(RunStatus::Stopped);
});

it('ignores a job delivery whose dispatch token was superseded', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create();

    $job = makeRunJob($participant);
    $participant->update(['dispatch_token' => (string) Str::uuid()]);

    $job->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Pending)
        ->and($participant->fresh()->started_at)->toBeNull();
});

it('aggregates participant statuses into the run status', function (array $statuses, RunStatus $expected) {
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();

    foreach ($statuses as $status) {
        RunParticipant::factory()->for($run)->create(['status' => $status]);
    }

    $run->refreshStatus();

    expect($run->fresh()->status)->toBe($expected);
})->with([
    'waiting outranks paused and finished' => [[RunStatus::Waiting, RunStatus::Paused, RunStatus::Completed], RunStatus::Waiting],
    'paused outranks finished' => [[RunStatus::Paused, RunStatus::Completed], RunStatus::Paused],
    'failure wins among finished' => [[RunStatus::Failed, RunStatus::Stopped, RunStatus::Completed], RunStatus::Failed],
    'stop beats completed' => [[RunStatus::Stopped, RunStatus::Completed], RunStatus::Stopped],
    'all completed' => [[RunStatus::Completed, RunStatus::Completed], RunStatus::Completed],
    'in-flight participant leaves the run untouched' => [[RunStatus::Running, RunStatus::Completed], RunStatus::Running],
]);

it('finalizes parked and pending participants directly on stop', function () {
    $run = Run::factory()->state(['status' => RunStatus::Running, 'restart_every_minutes' => 60])->create();
    $running = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Running]);
    $waiting = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Waiting, 'resume_at' => now()->addHour()]);
    $paused = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Paused]);
    $pending = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Pending]);

    $run->requestStop();

    expect($running->fresh()->status)->toBe(RunStatus::Stopping)
        ->and($waiting->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($waiting->fresh()->resume_at)->toBeNull()
        ->and($paused->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($pending->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($run->fresh()->restart_every_minutes)->toBeNull()
        ->and($run->currentSignal())->toBe(RunSignal::Stop);
});

it('parks waiting and pending participants directly on pause', function () {
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();
    $running = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Running]);
    $waiting = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Waiting, 'resume_at' => now()->addHour()]);
    $pending = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Pending]);

    $run->requestPause();

    expect($running->fresh()->status)->toBe(RunStatus::Pausing)
        ->and($waiting->fresh()->status)->toBe(RunStatus::Paused)
        ->and($waiting->fresh()->resume_at)->toBeNull()
        ->and($pending->fresh()->status)->toBe(RunStatus::Paused)
        ->and($run->currentSignal())->toBe(RunSignal::Pause);
});

it('resumes a paused quest list from its persisted position', function () {
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld();

    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Resume List']);
    $list->addQuest($catalog[742]->id);
    $list->addQuest($catalog[742]->id);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::QuestList,
            'config' => ['quest_list_id' => $list->id, 'stop_rage' => 2500],
            'status' => RunStatus::Running,
        ]))
        ->for($character)
        ->create(['progress' => ['position' => $list->items->last()->position]]);

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    // Only the second item ran: one quest completed this cycle, position past the end.
    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->progress['position'])->toBe($list->items->last()->position + 1)
        ->and($participant->wins)->toBe(5);
});
