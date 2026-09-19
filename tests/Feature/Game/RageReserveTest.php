<?php

use App\Game\Auth\LoginService;
use App\Game\Engine\RageReserveGate;
use App\Game\Enums\BrawlType;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Models\BrawlRound;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
    $this->travelTo('2026-09-20 12:00:00');
});

function brawlStarting(string $startsAt, BrawlType $type = BrawlType::Pvp, int $serverId = 1): BrawlRound
{
    return BrawlRound::create([
        'server_id' => $serverId,
        'type' => $type,
        'round_id' => fake()->unique()->numberBetween(100, 999),
        'starts_at' => $startsAt,
        'ends_at' => Carbon::parse($startsAt)->addHours(12),
        'synced_at' => now(),
    ]);
}

function reservingParticipant(array $run = []): RunParticipant
{
    return RunParticipant::factory()
        ->for(Run::factory()->state([
            'status' => RunStatus::Running,
            'config' => ['mob_names' => ['Kix Harvester'], 'run_count' => 1],
            'reserve_rage_for' => ['pvp-brawl'],
            'reserve_rage_hours' => 12,
            ...$run,
        ]))
        ->for(Character::factory()->for(Rga::factory()->withSession()))
        ->create();
}

it('parks a run until the brawl is over once the brawl is within the reserve hours', function () {
    brawlStarting('2026-09-21 00:00:00'); // 12 hours away
    $participant = reservingParticipant();
    fakeCombatWorld();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->resume_at->toDateTimeString())->toBe('2026-09-21 12:00:00')
        ->and($participant->last_activity)->toBe('Saving rage for the PvP Brawl (2026-09-21 00:00) — resumes 2026-09-21 12:00.')
        ->and($participant->wins)->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'somethingelse.php'));
});

it('keeps fighting while the brawl is still further away than the reserve hours', function () {
    brawlStarting('2026-09-21 00:00:01');
    $participant = reservingParticipant();
    fakeCombatWorld();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed)
        ->and($participant->fresh()->wins)->toBe(1);
});

it('ends a pass that was already under way when the reserve window opens', function () {
    brawlStarting('2026-09-21 00:30:00');
    $participant = reservingParticipant(['config' => ['mob_names' => ['Kix Harvester']]]);
    fakeCombatWorld();
    // The window opens while the character is on its way to the first fight.
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'ajax_changeroomb.php')) {
            $this->travelTo('2026-09-20 12:31:00');
        }

        return null;
    });

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Waiting)
        ->and($participant->fresh()->resume_at->toDateTimeString())->toBe('2026-09-21 12:30:00')
        ->and($participant->fresh()->last_activity)->toStartWith('Saving rage for the PvP Brawl');
});

it('ignores brawls it was not asked to save rage for', function (array $run, array $brawl) {
    brawlStarting('2026-09-20 18:00:00', ...$brawl);
    $participant = reservingParticipant($run);
    fakeCombatWorld();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed);
})->with([
    'a run with no events ticked' => [['reserve_rage_for' => null], []],
    'the other brawl type' => [[], ['type' => BrawlType::Faction]],
    'a brawl on the other server' => [[], ['serverId' => 2]],
]);

it('picks up again after the brawl instead of parking for the same round twice', function () {
    brawlStarting('2026-09-19 20:00:00'); // ended at 08:00 today
    $participant = reservingParticipant();
    fakeCombatWorld();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed);
});

it('never parks a brawl run, which exists to spend rage on the event', function () {
    brawlStarting('2026-09-20 18:00:00');
    $run = Run::factory()->create(['mode' => RunMode::PvpBrawl, 'reserve_rage_for' => ['pvp-brawl']]);
    $character = Character::factory()->create();

    expect(app(RageReserveGate::class)->nextWindowFor($run, $character))->toBeNull();
});
