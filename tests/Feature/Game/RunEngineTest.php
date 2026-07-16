<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Game\Exceptions\GameException;
use App\Jobs\RunMobJob;
use App\Jobs\RunQuestJob;
use App\Jobs\RunQuestListJob;
use App\Models\Character;
use App\Models\Mob;
use App\Models\QuestList;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
});

it('creates a run with participants and queues one job per character', function () {
    Queue::fake();

    $characters = Character::factory()->count(2)->for(Rga::factory()->withSession())->create();

    $this->artisan('outwar:run-start', [
        '--characters' => $characters->pluck('id')->map(fn ($id) => (string) $id)->all(),
        '--mob' => ['Kix Harvester'],
        '--max-kills' => 5,
        '--restart-every' => 60,
    ])->assertSuccessful();

    $run = Run::first();

    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->config['mob_names'])->toBe(['Kix Harvester'])
        ->and($run->restart_every_minutes)->toBe(60)
        ->and($run->participants)->toHaveCount(2);

    Queue::assertPushed(RunMobJob::class, 2);
    Queue::assertPushed(RunMobJob::class, fn (RunMobJob $job) => $job->connection === 'redis-runs' && $job->queue === 'runs');
});

it('executes a participant run to completion and settles the run status', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create();

    new RunMobJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->wins)->toBe(1)
        ->and($participant->last_activity)->toContain('No live targets')
        ->and($participant->started_at)->not->toBeNull()
        ->and($participant->finished_at)->not->toBeNull()
        ->and($participant->run->fresh()->status)->toBe(RunStatus::Completed);
});

it('honors a stop requested before the worker picked the job up', function () {
    fakeCombatWorld();

    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Stopping]))
        ->for(Character::factory()->for(Rga::factory()->withSession()))
        ->create(['status' => RunStatus::Stopping]);

    new RunMobJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($participant->fresh()->wins)->toBe(0)
        ->and($participant->run->fresh()->status)->toBe(RunStatus::Stopped);
});

it('marks the participant failed when the run throws', function () {
    fakeCombatWorld();

    // No mapped rooms for this mob → MobRunner throws.
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['config' => ['mob_names' => ['Ghost Mob']], 'status' => RunStatus::Running]))
        ->for(Character::factory()->for(Rga::factory()->withSession()))
        ->create();

    expect(fn () => new RunMobJob($participant)->handle(app(LoginService::class)))
        ->toThrow(GameException::class);

    expect($participant->fresh()->status)->toBe(RunStatus::Failed)
        ->and($participant->fresh()->last_activity)->toContain('No known rooms')
        ->and($participant->run->fresh()->status)->toBe(RunStatus::Failed)->not->toBeNull();
});

it('flags active participants when a stop is requested', function () {
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();
    $running = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Running]);
    $finished = RunParticipant::factory()->for($run)->create(['status' => RunStatus::Completed]);

    $this->artisan('outwar:run-stop', ['run' => $run->id])
        ->assertSuccessful()
        ->expectsOutputToContain('1 active participant(s) flagged');

    expect($running->fresh()->status)->toBe(RunStatus::Stopping)
        ->and($finished->fresh()->status)->toBe(RunStatus::Completed);
});

it('re-dispatches completed runs whose restart interval elapsed', function () {
    Queue::fake();

    $due = Run::factory()->restartEvery(60)->state([
        'status' => RunStatus::Completed,
        'last_started_at' => now()->subMinutes(90),
    ])->create();
    RunParticipant::factory()->for($due)->create(['status' => RunStatus::Completed, 'wins' => 7]);

    $notDue = Run::factory()->restartEvery(60)->state([
        'status' => RunStatus::Completed,
        'last_started_at' => now()->subMinutes(10),
    ])->create();
    RunParticipant::factory()->for($notDue)->create(['status' => RunStatus::Completed]);

    $this->artisan('outwar:runs-restart-due')
        ->assertSuccessful()
        ->expectsOutputToContain("Restarted run #{$due->id}");

    expect($due->fresh()->status)->toBe(RunStatus::Running)
        ->and($due->participants()->first()->status)->toBe(RunStatus::Pending)
        ->and($due->participants()->first()->wins)->toBe(7)
        ->and($notDue->fresh()->status)->toBe(RunStatus::Completed);

    Queue::assertPushed(RunMobJob::class, 1);
});

it('starts a quest run and queues a RunQuestJob with the quest config', function () {
    Queue::fake();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $this->artisan('outwar:run-start', [
        '--characters' => [(string) $character->id],
        '--mode' => 'quest',
        '--npc' => 'Stella',
        '--quest' => 742,
    ])->assertSuccessful();

    $run = Run::first();

    expect($run->mode)->toBe(RunMode::Quest)
        ->and($run->config['npc_name'])->toBe('Stella')
        ->and($run->config['quest_id'])->toBe(742);

    Queue::assertPushed(RunQuestJob::class, 1);
    Queue::assertNotPushed(RunMobJob::class);
});

it('rejects a quest run started without --npc/--quest', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $this->artisan('outwar:run-start', [
        '--characters' => [(string) $character->id],
        '--mode' => 'quest',
    ])->assertFailed()->expectsOutputToContain('Quest mode needs');

    expect(Run::count())->toBe(0);
});

it('executes a quest run job end to end and completes the run', function () {
    // Rooms 1–2 already exist from the combat seed; add the quest giver + objective mob.
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::Quest,
            'config' => ['npc_name' => 'Stella', 'quest_id' => 742, 'stop_rage' => 2500],
            'status' => RunStatus::Running,
        ]))
        ->for($character)
        ->create();

    new RunQuestJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed)
        ->and($participant->fresh()->wins)->toBe(5)
        ->and($participant->run->fresh()->status)->toBe(RunStatus::Completed);
});

it('starts a quest-list run and queues a RunQuestListJob', function () {
    Queue::fake();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    QuestList::create(['name' => 'Armins List']);

    $this->artisan('outwar:run-start', [
        '--characters' => [(string) $character->id],
        '--mode' => 'quest-list',
        '--list' => 'Armins List',
    ])->assertSuccessful();

    $run = Run::first();

    expect($run->mode)->toBe(RunMode::QuestList)
        ->and($run->config['quest_list_id'])->toBe(QuestList::first()->id);

    Queue::assertPushed(RunQuestListJob::class, 1);
});

it('executes a quest-list job end to end and completes the run', function () {
    // Rooms 1–2 exist from the combat seed; add the quest giver + objective mob.
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld();

    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest(742, 'Stella', 'Street Crawler');

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::QuestList,
            'config' => ['quest_list_id' => $list->id, 'stop_rage' => 2500],
            'status' => RunStatus::Running,
        ]))
        ->for($character)
        ->create();

    new RunQuestListJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed)
        ->and($participant->fresh()->wins)->toBe(5)
        ->and($participant->run->fresh()->status)->toBe(RunStatus::Completed);
});
