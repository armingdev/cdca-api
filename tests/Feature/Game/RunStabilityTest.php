<?php

use App\Game\Auth\LoginService;
use App\Game\Engine\RunLauncher;
use App\Game\Enums\CharacterActivity;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Game\Exceptions\CharactersBusyException;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
});

/**
 * Fake world where every game request answers with the boot sentinel —
 * the session was taken over elsewhere.
 */
function fakeBootedWorld(): void
{
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'outwar.com/index.php')) {
            return Http::response('', 302, [
                'Set-Cookie' => ['rg_sess_id=fresh; domain=.outwar.com', 'token=t; domain=.outwar.com', 'cuserid2=1; domain=.outwar.com'],
            ]);
        }

        return Http::response('Rampid Gaming Login');
    });
}

it('rejects enrolling a character that is already in an unfinished run', function (RunStatus $busyStatus) {
    Queue::fake();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create(['status' => $busyStatus]);

    $launcher = app(RunLauncher::class);

    expect(fn () => $launcher->launch(RunMode::Mob, collect([$character]), ['mob_names' => ['Kix Harvester']]))
        ->toThrow(CharactersBusyException::class, $character->name);
})->with([
    'running' => [RunStatus::Running],
    'waiting' => [RunStatus::Waiting],
    'paused' => [RunStatus::Paused],
    'pending' => [RunStatus::Pending],
]);

it('allows re-enrolling a character once its previous run finished', function () {
    Queue::fake();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Completed]))
        ->for($character)
        ->create(['status' => RunStatus::Completed]);

    $run = app(RunLauncher::class)->launch(RunMode::Mob, collect([$character]), ['mob_names' => ['Kix Harvester']]);

    expect($run->participants)->toHaveCount(1);
});

it('fails loudly instead of double-driving a locked character', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create();

    // Another worker holds this character's run lock.
    $foreign = Cache::lock("character-run:{$character->id}", 60);
    expect($foreign->get())->toBeTrue();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Failed)
        ->and($participant->fresh()->last_activity)->toContain('already driven');

    $foreign->release();
});

it('releases the character lock when the run ends', function () {
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'status' => RunStatus::Running,
            'config' => ['mob_names' => ['Kix Harvester'], 'run_count' => 1],
        ]))
        ->for($character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed)
        ->and(Cache::lock("character-run:{$character->id}", 5)->get())->toBeTrue();
});

it('finalizes a run whose worker was killed mid-drive', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create(['status' => RunStatus::Running]);

    // A timeout kills the worker outright, so the engine's own catch block
    // never runs — only the queue's failed() hook does.
    Cache::lock("character-run:{$character->id}", 7800)->get();

    makeRunJob($participant)->failed(new RuntimeException('Job has timed out.'));

    expect($participant->fresh()->status)->toBe(RunStatus::Failed)
        ->and($participant->fresh()->last_activity)->toContain('timed out')
        ->and($participant->fresh()->run->status)->toBe(RunStatus::Failed)
        ->and($character->fresh()->status)->not->toBe(CharacterActivity::Running)
        ->and(Cache::lock("character-run:{$character->id}", 5)->get())->toBeTrue();
});

it('leaves an already-finished participant alone when the failure arrives late', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create(['status' => RunStatus::Completed, 'last_activity' => 'All done.']);

    makeRunJob($participant)->failed(new RuntimeException('Job has timed out.'));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed)
        ->and($participant->fresh()->last_activity)->toBe('All done.');
});

it('re-logs in once after a session collision and parks the participant to resume', function () {
    fakeBootedWorld();

    $rga = Rga::factory()->withSession()->create();
    $character = Character::factory()->for($rga)->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->resume_at->diffInSeconds(now()->addMinute(), true))->toBeLessThan(5)
        ->and($participant->progress['relogin_attempts'])->toBe(1)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_ACTIVE);

    Http::assertSentCount(2); // the collided request + exactly one re-login
});

it('does not stampede the login when a sibling already holds the re-login lock', function () {
    fakeBootedWorld();

    $rga = Rga::factory()->withSession()->create();
    $character = Character::factory()->for($rga)->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create();

    $sibling = Cache::lock("rga-relogin:{$rga->id}", 60);
    expect($sibling->get())->toBeTrue();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Waiting);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'outwar.com/index.php'));

    $sibling->release();
});

it('gives up after the re-login attempt budget is spent', function () {
    fakeBootedWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $participant = RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create(['progress' => ['relogin_attempts' => 3]]);

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Failed)
        ->and($participant->fresh()->last_activity)->toContain('giving up');
});

it('creates a future-scheduled run as pending and flips it to running at first pickup', function () {
    Queue::fake();
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $run = app(RunLauncher::class)->launch(
        RunMode::Mob,
        collect([$character]),
        ['mob_names' => ['Kix Harvester'], 'run_count' => 1],
        startAt: now()->addHours(2),
    );

    expect($run->status)->toBe(RunStatus::Pending);

    $this->travelTo(now()->addHours(2)->addMinute());

    makeRunJob($run->participants()->first())->handle(app(LoginService::class));

    expect($run->fresh()->status)->toBe(RunStatus::Completed)
        ->and($run->participants()->first()->status)->toBe(RunStatus::Completed);
});
