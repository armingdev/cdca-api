<?php

use App\Game\Auth\LoginService;
use App\Game\Engine\RunDispatcher;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Game\Parsers\HitlistParser;
use App\Jobs\RunBrawlJob;
use App\Jobs\RunPvpJob;
use App\Models\AttackCooldown;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function pvpParticipant(RunMode $mode, array $config = []): RunParticipant
{
    return RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => $mode,
            'config' => array_merge(['stop_rage' => 0, 'attacks_per_target' => 1], $config),
            'status' => RunStatus::Running,
        ]))
        ->for(Character::factory()->for(Rga::factory()->withSession())->create(['server_id' => 1]))
        ->create();
}

it('dispatches the right job for every pvp mode', function (RunMode $mode, string $job) {
    Queue::fake();

    $participant = pvpParticipant($mode);

    app(RunDispatcher::class)->dispatch($participant);

    Queue::assertPushed($job);
})->with([
    'attack list' => [RunMode::PvpAttackList, RunPvpJob::class],
    'crew hitlist' => [RunMode::PvpCrewHitlist, RunPvpJob::class],
    'crew members' => [RunMode::PvpCrewMembers, RunPvpJob::class],
    'pvp brawl' => [RunMode::PvpBrawl, RunBrawlJob::class],
    'faction brawl' => [RunMode::PvpFactionBrawl, RunBrawlJob::class],
]);

it('runs crew-hitlist mode off a single list request', function () {
    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'attacklog') => Http::response('<html><table></table></html>'),
            str_contains($url, 'crew_hitlist') => Http::response(gameFixture('crew_hitlist.html')),
            str_contains($url, 'userstats') => Http::response(json_encode(['exp' => '1', 'rage' => '90000', 'level' => '95', 'width' => 0])),
            str_contains($url, 'somethingelse') => Http::response('', 302, ['Location' => '/plrattack/900/']),
            str_contains($url, 'plrattack/900') => Http::response('var battle_result = "x has gained 5 experience!";'),
            default => Http::response('<html></html>'),
        };
    });

    $participant = pvpParticipant(RunMode::PvpCrewHitlist);

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed);

    // Hitlist targets arrive pre-hashed, so no search is needed per target.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'playersearch.php'));
});

it('finishes a pass with every target on cooldown as Completed, so the run keeps recurring', function () {
    // RunsRestartDueCommand only re-dispatches Completed runs. If an
    // all-blocked pass ended Stuck, a recurring PvP run would silently die
    // after its first pass.
    $participant = pvpParticipant(RunMode::PvpCrewHitlist);

    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'attacklog') => Http::response('<html><table></table></html>'),
            str_contains($url, 'crew_hitlist') => Http::response(gameFixture('crew_hitlist.html')),
            str_contains($url, 'userstats') => Http::response(json_encode(['exp' => '1', 'rage' => '90000', 'level' => '95', 'width' => 0])),
            default => Http::response('<html></html>'),
        };
    });

    // Block every target the fixture carries, whatever it holds.
    $entries = new HitlistParser()->parse(gameFixture('crew_hitlist.html'));

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        AttackCooldown::record($participant->character_id, $entry->target->playerId, $entry->target->name);
    }

    makeRunJob($participant)->handle(app(LoginService::class));

    $fresh = $participant->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed)
        ->and($fresh->last_activity)->toContain('on cooldown');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'somethingelse.php'));
});

it('enters a brawl but does not attack while the mechanics are unverified', function () {
    config(['outwar.brawl.attacks_verified' => false]);

    $participant = pvpParticipant(RunMode::PvpBrawl, ['auto_enter_brawl' => true]);
    $participant->character->update(['suid' => 113903]);

    Http::fake(['*' => Http::response(gameFixture('closedpvp_brawl_prestart.html'))]);

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed)
        ->and($participant->fresh()->last_activity)->toContain('not yet verified');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'somethingelse.php'));
});

it('skips a brawl pass when the character is not entered and auto-enter is off', function () {
    $participant = pvpParticipant(RunMode::PvpBrawl, ['auto_enter_brawl' => false]);

    Http::fake(['*' => Http::response(gameFixture('closedpvp_brawl_prestart.html'))]);

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->last_activity)->toContain('auto-enter is off');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'enter=1'));
});
