<?php

use App\Game\Enums\RunStatus;
use App\Jobs\RunMobJob;
use App\Jobs\RunPvpJob;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
});

it('starts a mob run for owned characters and queues a job per character', function () {
    Queue::fake();
    $characters = Character::factory()->for($this->rga)->count(2)->create();

    $response = $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => $characters->pluck('id')->all(),
        'mobs' => ['Kix Harvester'],
        'max_kills' => 5,
        'cast_on_start' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.mode', 'mob')
        ->assertJsonPath('data.cast_on_start', true)
        ->assertJsonCount(2, 'data.participants');

    $run = Run::first();
    expect($run->user_id)->toBe($this->user->id)
        ->and($run->config['mob_names'])->toBe(['Kix Harvester']);

    Queue::assertPushed(RunMobJob::class, 2);
});

it('starts a pvp run', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'pvp',
        'characters' => [$character->id],
        'targets' => ['OFFENSIVE'],
        'attacks_per_target' => 3,
    ])->assertCreated()->assertJsonPath('data.config.attacks_per_target', 3);

    Queue::assertPushed(RunPvpJob::class, 1);
});

it('accepts and stores the mob pass options', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
        'run_count' => 3,
        'attack_interval_seconds' => 300,
    ])->assertCreated()
        ->assertJsonPath('data.config.run_count', 3)
        ->assertJsonPath('data.config.attack_interval_seconds', 300);

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
        'attack_interval_seconds' => 5,
    ])->assertStatus(422)->assertJsonValidationErrorFor('attack_interval_seconds');
});

it('stores the smart flag on the run config', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'quest',
        'characters' => [$character->id],
        'npc' => 'Stella',
        'quest_id' => 742,
        'smart' => true,
    ])->assertCreated()->assertJsonPath('data.config.smart', true);

    expect(Run::first()->config['smart'])->toBeTrue();
});

it('defaults the smart flag to off', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
    ])->assertCreated()->assertJsonPath('data.config.smart', false);
});

it('rejects a run for a character already enrolled in an active run', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();
    RunParticipant::factory()
        ->for(Run::factory()->for($this->user)->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create(['status' => RunStatus::Running]);

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('characters');

    Queue::assertNothingPushed();
});

it('rejects a run that uses characters the user does not own', function () {
    Queue::fake();
    $foreign = Character::factory()->for(Rga::factory()->for(User::factory()))->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$foreign->id],
        'mobs' => ['Kix Harvester'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('characters');

    Queue::assertNothingPushed();
});

it('validates mode-specific fields', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    // quest mode without npc/quest_id
    $this->postJson('/api/v1/runs', ['mode' => 'quest', 'characters' => [$character->id]])
        ->assertStatus(422);
});

it('shows a run and stops it gracefully', function () {
    $run = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();
    $participant = RunParticipant::factory()->for($run)->for(Character::factory()->for($this->rga))->create(['status' => RunStatus::Running]);

    $this->getJson("/api/v1/runs/{$run->id}")->assertOk()->assertJsonPath('data.id', $run->id);

    $this->postJson("/api/v1/runs/{$run->id}/stop")
        ->assertOk()
        ->assertJsonPath('data.status', 'stopping');

    expect($participant->fresh()->status)->toBe(RunStatus::Stopping);
});

it('pauses a running run and resumes it with a fresh dispatch', function () {
    Queue::fake();

    $run = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();
    $waiting = RunParticipant::factory()->for($run)->for(Character::factory()->for($this->rga))
        ->create(['status' => RunStatus::Waiting, 'resume_at' => now()->addHour()]);

    $this->postJson("/api/v1/runs/{$run->id}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', 'paused');

    expect($waiting->fresh()->status)->toBe(RunStatus::Paused)
        ->and($waiting->fresh()->resume_at)->toBeNull();

    $this->postJson("/api/v1/runs/{$run->id}/resume")
        ->assertOk()
        ->assertJsonPath('data.status', 'running');

    expect($waiting->fresh()->status)->toBe(RunStatus::Pending)
        ->and($waiting->fresh()->dispatch_token)->not->toBeNull();

    Queue::assertPushed(RunMobJob::class, 1);
});

it('rejects pausing a finished run and resuming a non-paused run', function () {
    $finished = Run::factory()->for($this->user)->state(['status' => RunStatus::Completed])->create();
    $running = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();

    $this->postJson("/api/v1/runs/{$finished->id}/pause")->assertStatus(422);
    $this->postJson("/api/v1/runs/{$running->id}/resume")->assertStatus(422);
});

it('stops parked participants immediately when a paused run is stopped', function () {
    $run = Run::factory()->for($this->user)->state(['status' => RunStatus::Paused])->create();
    $paused = RunParticipant::factory()->for($run)->for(Character::factory()->for($this->rga))
        ->create(['status' => RunStatus::Paused]);

    $this->postJson("/api/v1/runs/{$run->id}/stop")
        ->assertOk()
        ->assertJsonPath('data.status', 'stopped');

    expect($paused->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($paused->fresh()->finished_at)->not->toBeNull();
});

it('deletes only finished runs', function () {
    $finished = Run::factory()->for($this->user)->state(['status' => RunStatus::Completed])->create();
    RunParticipant::factory()->for($finished)->for(Character::factory()->for($this->rga))->create(['status' => RunStatus::Completed]);

    $this->deleteJson("/api/v1/runs/{$finished->id}")->assertOk();
    expect(Run::find($finished->id))->toBeNull()
        ->and(RunParticipant::where('run_id', $finished->id)->count())->toBe(0);

    $live = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();
    $this->deleteJson("/api/v1/runs/{$live->id}")->assertStatus(422);

    $foreign = Run::factory()->for(User::factory())->state(['status' => RunStatus::Completed])->create();
    $this->deleteJson("/api/v1/runs/{$foreign->id}")->assertForbidden();
});

it('lists only the user\'s runs and forbids others', function () {
    Run::factory()->for($this->user)->create();
    $other = Run::factory()->for(User::factory())->create();

    $this->getJson('/api/v1/runs')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/runs/{$other->id}")->assertForbidden();
});
