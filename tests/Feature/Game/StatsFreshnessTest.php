<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\CharacterActivity;
use App\Game\Enums\RunSignal;
use App\Game\Enums\RunStatus;
use App\Jobs\RefreshCharacterStatsJob;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('refreshes rage, exp, and level from the game in the background job', function () {
    Http::fake(['*userstats.php*' => Http::response(json_encode([
        'exp' => '123,456', 'rage' => '80,906', 'level' => '75', 'width' => 0,
    ]))]);

    $character = Character::factory()->for(Rga::factory()->withSession())->create(['rage' => null, 'last_stats_at' => null]);

    new RefreshCharacterStatsJob($character)->handle();

    $character->refresh();

    expect($character->rage)->toBe(80906)
        ->and($character->exp)->toBe(123456)
        ->and($character->level)->toBe(75)
        ->and($character->last_stats_at)->not->toBeNull();
});

it('skips the refresh silently when the RGA has no session', function () {
    Http::fake();

    $character = Character::factory()->for(Rga::factory())->create(['rage' => null]);

    new RefreshCharacterStatsJob($character)->handle();

    expect($character->fresh()->rage)->toBeNull();
    Http::assertNothingSent();
});

it('queues refreshes only for idle, stale characters on connected RGAs', function () {
    Queue::fake();

    $connected = Rga::factory()->withSession()->create();
    $stale = Character::factory()->for($connected)->create(['last_stats_at' => now()->subHour()]);
    $neverRead = Character::factory()->for($connected)->create(['last_stats_at' => null]);
    $fresh = Character::factory()->for($connected)->create(['last_stats_at' => now()->subMinutes(5)]);
    $running = Character::factory()->for($connected)->create(['last_stats_at' => now()->subHour(), 'status' => CharacterActivity::Running]);
    $sessionless = Character::factory()->for(Rga::factory())->create(['last_stats_at' => now()->subHour()]);

    $this->artisan('outwar:stats-refresh-stale')
        ->assertSuccessful()
        ->expectsOutputToContain('Queued 2 stat refresh(es)');

    Queue::assertPushed(RefreshCharacterStatsJob::class, 2);
    Queue::assertPushed(RefreshCharacterStatsJob::class, fn ($job) => $job->character->is($stale) || $job->character->is($neverRead));
});

it('refreshes a character synchronously through the API', function () {
    Http::fake(['*userstats.php*' => Http::response(json_encode([
        'exp' => '9,999', 'rage' => '4,200', 'level' => '61', 'width' => 0,
    ]))]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $character = Character::factory()->for(Rga::factory()->for($user)->withSession())->create(['rage' => null]);

    $this->postJson("/api/v1/characters/{$character->id}/refresh-stats")
        ->assertOk()
        ->assertJsonPath('data.rage', 4200)
        ->assertJsonPath('data.level', 61);

    $foreign = Character::factory()->for(Rga::factory()->for(User::factory())->withSession())->create();
    $this->postJson("/api/v1/characters/{$foreign->id}/refresh-stats")->assertForbidden();

    $disconnected = Character::factory()->for(Rga::factory()->for($user))->create();
    $this->postJson("/api/v1/characters/{$disconnected->id}/refresh-stats")->assertStatus(422);
});

it('mirrors the run lifecycle onto the character activity column', function () {
    seedCombatWorld();
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state(['status' => RunStatus::Running])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    expect($character->status)->toBe(CharacterActivity::Idle);

    // Pause signal: the engine parks the participant → character shows paused.
    $run->signal(RunSignal::Pause);
    makeRunJob($participant)->handle(app(LoginService::class));
    expect($character->fresh()->status)->toBe(CharacterActivity::Paused);

    // Stop finalizes the parked participant → character back to idle.
    $run->fresh()->requestStop();
    expect($character->fresh()->status)->toBe(CharacterActivity::Idle);
});

it('marks the character waiting while parked on a circ or interval wait', function () {
    seedCombatWorld();
    fakeCombatWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state([
        'config' => ['mob_names' => ['Kix Harvester'], 'run_count' => 2, 'attack_interval_seconds' => 300],
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Waiting)
        ->and($character->fresh()->status)->toBe(CharacterActivity::Waiting);
});
