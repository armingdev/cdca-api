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

it('lists only the user\'s runs and forbids others', function () {
    Run::factory()->for($this->user)->create();
    $other = Run::factory()->for(User::factory())->create();

    $this->getJson('/api/v1/runs')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/runs/{$other->id}")->assertForbidden();
});
