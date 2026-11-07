<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\CharacterActivity;
use App\Game\Enums\RunEventType;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunSignal;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestList;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Cache;
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

it('parks a running participant for a quick resume when the worker is told to quit', function () {
    fakeCombatWorld();
    $this->freezeTime();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create([
        'progress' => ['kills_done' => 3, 'rage_waits' => 2],
    ]);

    $job = makeRunJob($participant);
    $job->interrupted(SIGTERM);
    $job->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->last_activity)->toContain('Worker restarting')
        ->and($participant->resume_at->timestamp)->toBe(now()->addMinute()->timestamp)
        ->and($participant->finished_at)->toBeNull()
        ->and($participant->progress)->toMatchArray(['kills_done' => 3, 'rage_waits' => 2])
        ->and(Cache::lock("character-run:{$character->id}", 10)->get())->toBeTrue();
});

it('honours a stop request over a worker shutdown when the cache signal was lost', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    // The stop lands after pickup and only in the database — no cache signal.
    // Pickup claims the participant with an atomic query (no model event), so
    // hook the next step of the pickup: the character turning Running.
    Character::updated(function (Character $updated) use ($participant): void {
        if ($updated->status === CharacterActivity::Running) {
            RunParticipant::whereKey($participant->id)->update(['status' => RunStatus::Stopping]);
        }
    });

    $job = makeRunJob($participant);
    $job->interrupted(SIGTERM);
    $job->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($participant->fresh()->resume_at)->toBeNull();
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

it('parks a quest rage-out for the next rage tick rather than failing it', function () {
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

    // Rage comes back on the game's hourly tick, so running out is a pause in
    // the work, not the end of it — and never a failure.
    expect($participant->fresh()->status)->toBe(RunStatus::Waiting)
        ->and($participant->fresh()->last_activity)->toContain('Rage below')
        ->and($participant->fresh()->last_activity)->toContain('Waiting for rage')
        ->and($participant->fresh()->resume_at)->not->toBeNull()
        ->and($participant->run->fresh()->status)->toBe(RunStatus::Waiting);
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

/**
 * The user-facing end of the Nov 2026 report: a list that put endgame quests
 * first used to read "Quest list complete." with almost nothing done.
 */
it('ends a quest-list run with an honest tally, keeping the deferred quests in its progress', function () {
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld(level: 20);

    $catalog = seedQuestCatalog();
    $endgame = Quest::factory()->create(['game_quest_id' => 2630, 'name' => 'Intergalactic Union', 'giver' => 'Zugas', 'required_level' => 90]);
    $list = QuestList::create(['name' => '80-95 GOOD']);
    $list->addQuest($endgame->id);
    $list->addQuest($catalog[742]->id);

    $character = Character::factory()->for(Rga::factory()->withSession())->create(['level' => 20]);
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::QuestList,
            'config' => ['quest_list_ids' => [$list->id], 'stop_rage' => 2500],
            'status' => RunStatus::Running,
        ]))
        ->for($character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->last_activity)->toBe('Quest list done for now: 1 completed, 1 deferred until level 90.')
        ->and($participant->progress)->toMatchArray([
            'quests_completed' => 1,
            'quests_skipped' => 0,
            'quests_deferred' => 1,
            'deferred' => [$endgame->id => 'level_too_low'],
        ]);
    $this->assertDatabaseHas('run_events', [
        'run_participant_id' => $participant->id,
        'type' => RunEventType::QuestDeferred->value,
        'message' => "Deferred 1 quest(s) in '80-95 GOOD' until the character is ready: 1 need level 90.",
    ]);
});

it('resumes a chained quest-list run in the list it stopped in', function () {
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld();

    $catalog = seedQuestCatalog();
    $first = QuestList::create(['name' => 'First']);
    $first->addQuest($catalog[742]->id);
    $second = QuestList::create(['name' => 'Second']);
    $second->addQuest($catalog[742]->id);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::QuestList,
            'config' => ['quest_list_ids' => [$first->id, $second->id], 'quest_list_id' => $first->id, 'stop_rage' => 2500],
            'status' => RunStatus::Running,
        ]))
        ->for($character)
        // Parked at the start of the second list.
        ->create(['progress' => ['list_index' => 1, 'position' => 0]]);

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    // The first list was not replayed: exactly one quest ran, in the second list.
    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->wins)->toBe(5)
        ->and($participant->progress['list_index'])->toBe(1)
        ->and($participant->progress['position'])->toBe($second->items->last()->position + 1);
});
