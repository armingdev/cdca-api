<?php

use App\Game\Enums\CharacterActivity;
use App\Game\Enums\RunStatus;
use App\Jobs\RunMobJob;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function stalledParticipant(RunStatus $status, array $attributes = []): RunParticipant
{
    return RunParticipant::factory()
        ->for(Run::factory()->state(['status' => RunStatus::Running]))
        ->for(Character::factory()->for(Rga::factory()->withSession()))
        ->create(['status' => $status, 'heartbeat_at' => now()->subMinutes(11), ...$attributes]);
}

it('parks a running participant whose worker stopped sending heartbeats', function () {
    $participant = stalledParticipant(RunStatus::Running);
    Cache::lock("character-run:{$participant->character_id}", 7800)->get();

    $this->artisan('outwar:runs-recover-stalled')->assertSuccessful();

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->last_activity)->toBe('The worker driving this run stopped responding. Resuming shortly.')
        ->and($participant->resume_at->isFuture())->toBeTrue()
        ->and($participant->progress['worker_deaths'])->toBe(1)
        ->and($participant->character->status)->toBe(CharacterActivity::fromRunStatus(RunStatus::Waiting))
        ->and(Cache::lock("character-run:{$participant->character_id}", 5)->get())->toBeTrue();
});

it('finishes a stop or pause the dead worker never got to honour', function (RunStatus $requested, RunStatus $settled) {
    $participant = stalledParticipant($requested);

    $this->artisan('outwar:runs-recover-stalled')->assertSuccessful();

    expect($participant->fresh()->status)->toBe($settled);
})->with([
    'stopping' => [RunStatus::Stopping, RunStatus::Stopped],
    'pausing' => [RunStatus::Pausing, RunStatus::Paused],
]);

it('leaves participants alone that are alive, queued, or already parked', function (RunStatus $status, array $attributes) {
    $participant = stalledParticipant($status, $attributes);

    $this->artisan('outwar:runs-recover-stalled')->assertSuccessful();

    expect($participant->fresh()->status)->toBe($status)
        ->and($participant->fresh()->progress)->toBeNull();
})->with([
    'running with a recent heartbeat' => [RunStatus::Running, ['heartbeat_at' => now()->subMinute()]],
    'pending behind a full set of workers' => [RunStatus::Pending, []],
    'waiting for rage' => [RunStatus::Waiting, ['resume_at' => now()->addHour()]],
]);

it('recovers a participant that died before its first heartbeat', function () {
    $participant = stalledParticipant(RunStatus::Running, ['heartbeat_at' => null]);
    RunParticipant::whereKey($participant->id)->toBase()->update(['updated_at' => now()->subMinutes(11)]);

    $this->artisan('outwar:runs-recover-stalled')->assertSuccessful();

    expect($participant->fresh()->status)->toBe(RunStatus::Waiting);
});

it('hands a recovered participant to a new job under a new dispatch token', function () {
    Queue::fake([RunMobJob::class]);
    $deadWorkersToken = '0b1c7e0e-5a3f-4d7c-9a51-6f2f0c1d2e3f';
    $participant = stalledParticipant(RunStatus::Running, ['dispatch_token' => $deadWorkersToken]);

    $this->artisan('outwar:runs-recover-stalled');
    $this->travel(2)->minutes();
    $this->artisan('outwar:runs-resume-due');

    Queue::assertPushed(RunMobJob::class, fn (RunMobJob $job) => $job->dispatchToken !== $deadWorkersToken
        && $job->dispatchToken === $participant->fresh()->dispatch_token);
});
