<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\RunEventType;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
});

function mobParticipant(RunStatus $status = RunStatus::Pending, array $attributes = []): RunParticipant
{
    return RunParticipant::factory()
        ->for(Run::factory()->state([
            'status' => RunStatus::Running,
            'config' => ['mob_names' => ['Kix Harvester'], 'run_count' => 1],
        ]))
        ->for(Character::factory()->for(Rga::factory()->withSession()))
        ->create(['status' => $status, ...$attributes]);
}

/**
 * Swap in a cache store that behaves like Redis dropping its connection, but
 * only for the run-signal reads — locks and everything else keep working, as
 * they did in the outage this models.
 */
function loseRedisForRunSignals(): void
{
    Cache::extend('redis-down-for-signals', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            if (str_starts_with($key, 'run:signal:')) {
                throw new RedisException('read error on connection to 127.0.0.1:6379');
            }

            return parent::get($key);
        }
    }));

    config([
        'cache.stores.redis-down-for-signals' => ['driver' => 'redis-down-for-signals'],
        'cache.default' => 'redis-down-for-signals',
    ]);
}

describe('a job redelivered after its worker died', function () {
    it('parks the participant to resume instead of failing it', function (Throwable $verdict) {
        $participant = mobParticipant(RunStatus::Running);
        Cache::lock("character-run:{$participant->character_id}", 7800)->get();

        makeRunJob($participant)->failed($verdict);

        $participant->refresh();

        expect($participant->status)->toBe(RunStatus::Waiting)
            ->and($participant->last_activity)->toBe('The worker driving this run died. Resuming shortly.')
            ->and($participant->resume_at->isFuture())->toBeTrue()
            ->and($participant->progress['worker_deaths'])->toBe(1)
            ->and(Cache::lock("character-run:{$participant->character_id}", 5)->get())->toBeTrue();
    })->with([
        'redelivered past its tries' => [new MaxAttemptsExceededException('App\Jobs\RunMobJob has been attempted too many times.')],
        'killed by the job timeout' => [new TimeoutExceededException('App\Jobs\RunMobJob has timed out.')],
    ]);

    it('fails the participant once its worker has died three times in a row', function () {
        $participant = mobParticipant(RunStatus::Running, ['progress' => ['worker_deaths' => 3]]);

        makeRunJob($participant)->failed(new MaxAttemptsExceededException('attempted too many times'));

        $participant->refresh();

        expect($participant->status)->toBe(RunStatus::Failed)
            ->and($participant->last_activity)->toContain('Gave up after 3 worker deaths')
            ->and($participant->run->status)->toBe(RunStatus::Failed);
    });

    it('leaves a participant that has since been re-dispatched alone', function () {
        $participant = mobParticipant(RunStatus::Running);
        $staleJob = makeRunJob($participant);
        $lock = Cache::lock("character-run:{$participant->character_id}", 7800);
        $lock->get();

        // The run was restarted: a newer dispatch owns the participant and its lock.
        $participant->update(['dispatch_token' => (string) Str::uuid(), 'last_activity' => 'Beat Kix Harvester']);

        $staleJob->failed(new MaxAttemptsExceededException('attempted too many times'));

        expect($participant->fresh()->status)->toBe(RunStatus::Running)
            ->and($participant->fresh()->last_activity)->toBe('Beat Kix Harvester')
            ->and(Cache::lock("character-run:{$participant->character_id}", 5)->get())->toBeFalse();
    });
});

describe('a job that lost its participant mid-run', function () {
    it('ends its pass without writing over the dispatch that replaced it', function () {
        config(['outwar.runs.heartbeat_seconds' => 0]);
        $participant = mobParticipant();
        $job = makeRunJob($participant);
        $replacementToken = (string) Str::uuid();

        Http::fake(function () use ($participant, $replacementToken) {
            // Stalled-run recovery re-dispatched the participant while this
            // job was still inside a game request.
            RunParticipant::whereKey($participant->id)->update([
                'dispatch_token' => $replacementToken,
                'status' => RunStatus::Pending,
                'last_activity' => 'Resuming…',
            ]);

            return null;
        });
        fakeCombatWorld();

        $job->handle(app(LoginService::class));

        $participant->refresh();

        expect($participant->status)->toBe(RunStatus::Pending)
            ->and($participant->last_activity)->toBe('Resuming…')
            ->and($participant->dispatch_token)->toBe($replacementToken)
            ->and(RunEvent::where('run_participant_id', $participant->id)->whereIn('type', [RunEventType::Parked, RunEventType::Stopped, RunEventType::Failed])->exists())->toBeFalse();
    });
});

describe('a network failure during the run', function () {
    it('parks the participant to retry instead of failing it', function () {
        $participant = mobParticipant();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Resolving timed out after 5006 milliseconds'));

        makeRunJob($participant)->handle(app(LoginService::class));

        $participant->refresh();

        expect($participant->status)->toBe(RunStatus::Waiting)
            ->and($participant->last_activity)->toContain('Connection trouble')
            ->and($participant->resume_at->isFuture())->toBeTrue()
            ->and($participant->progress['transient_failures'])->toBe(1)
            ->and($participant->run->fresh()->status)->not->toBe(RunStatus::Failed);
    });

    it('fails the participant once the network has failed six times in a row', function () {
        $participant = mobParticipant(RunStatus::Pending, ['progress' => ['transient_failures' => 5]]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Resolving timed out'));

        makeRunJob($participant)->handle(app(LoginService::class));

        expect($participant->fresh()->status)->toBe(RunStatus::Failed)
            ->and($participant->fresh()->last_activity)->toContain('Resolving timed out');
    });

    it('starts the retry budget over after a pass that completed cleanly', function () {
        $participant = mobParticipant(RunStatus::Pending, ['progress' => ['transient_failures' => 4, 'worker_deaths' => 2]]);
        fakeCombatWorld();

        makeRunJob($participant)->handle(app(LoginService::class));

        expect($participant->fresh()->status)->toBe(RunStatus::Completed)
            ->and($participant->fresh()->progress['transient_failures'])->toBe(0)
            ->and($participant->fresh()->progress['worker_deaths'])->toBe(0);
    });
});

describe('an unreachable Redis during the stop check', function () {
    it('keeps the run going by reading the participant row instead', function () {
        $participant = mobParticipant();
        fakeCombatWorld();
        loseRedisForRunSignals();

        makeRunJob($participant)->handle(app(LoginService::class));

        expect($participant->fresh()->status)->toBe(RunStatus::Completed);
    });

    it('still honours a stop the user requested', function () {
        $participant = mobParticipant();
        loseRedisForRunSignals();
        $job = makeRunJob($participant);

        // The stop landed in the database; only its cache mirror is unreadable.
        Http::fake(function () use ($participant) {
            RunParticipant::whereKey($participant->id)->where('status', RunStatus::Running)->update(['status' => RunStatus::Stopping]);

            return null;
        });
        fakeCombatWorld();

        $job->handle(app(LoginService::class));

        expect($participant->fresh()->status)->toBe(RunStatus::Stopped);
    });
});

it('ends the pass and parks when the job is about to reach its timeout', function () {
    $participant = mobParticipant();
    $job = makeRunJob($participant);
    $job->timeout = 600;
    fakeCombatWorld();

    $job->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Waiting)
        ->and($participant->fresh()->last_activity)->toContain('resuming shortly');
});
