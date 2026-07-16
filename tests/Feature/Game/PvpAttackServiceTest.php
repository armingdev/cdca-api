<?php

use App\Game\Combat\PvpAttackService;
use App\Game\Enums\BattleKind;
use App\Game\Enums\BattleOutcome;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('searches players and returns targets carrying the attack hash', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*playersearch.php*' => Http::response(gameFixture('playersearch_results.html'))]);

    $target = PvpAttackService::forCharacter($character)->findTarget('OFFENSIVE');

    expect($target->playerId)->toBe(302)
        ->and($target->hash)->toBe('5648d8cd');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'playersearch.php')
        && $request['searchType'] == 0
        && $request['search'] === 'OFFENSIVE');
});

it('attacks a target and records a pvp win', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*playersearch.php*' => Http::response(gameFixture('playersearch_results.html')),
        '*somethingelse.php*' => Http::response('', 302, ['Location' => 'https://sigil.outwar.com/plrattack/808/']),
        '*plrattack/808*' => Http::response('var battle_result = "OFFENSIVE has gained 40 experience!"; var defender_name = "OFFENSIVE";'),
    ]);

    $service = PvpAttackService::forCharacter($character);
    $event = $service->attack($service->findTarget('OFFENSIVE'), rage: 50, message: 'gg');

    expect($event->kind)->toBe(BattleKind::Pvp)
        ->and($event->outcome)->toBe(BattleOutcome::Win)
        ->and($event->opponent_name)->toBe('OFFENSIVE')
        ->and($event->battle_id)->toBe(808)
        ->and($event->exp_gained)->toBe(40);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'somethingelse.php')
        && str_contains($request->url(), 'attackid=302')
        && str_contains($request->body(), 'hash=5648d8cd')
        && str_contains($request->body(), 'rage=50'));
});

it('records a pvp failure when the attack does not redirect', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*playersearch.php*' => Http::response(gameFixture('playersearch_results.html')),
        '*somethingelse.php*' => Http::response('<html>You cannot attack that player right now.</html>'),
    ]);

    $service = PvpAttackService::forCharacter($character);
    $event = $service->attack($service->findTarget('OFFENSIVE'));

    expect($event->outcome)->toBe(BattleOutcome::Failed)
        ->and($event->kind)->toBe(BattleKind::Pvp)
        ->and($event->fail_reason)->toContain('cannot attack');
});

it('clamps the pvp rage slider to 2-50', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*playersearch.php*' => Http::response(gameFixture('playersearch_results.html')),
        '*somethingelse.php*' => Http::response('', 302, ['Location' => '/plrattack/1/']),
        '*plrattack/1*' => Http::response('var battle_result = "x has gained 1 experience!";'),
    ]);

    $service = PvpAttackService::forCharacter($character);
    $service->attack($service->findTarget('OFFENSIVE'), rage: 9999);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'somethingelse.php') && str_contains($request->body(), 'rage=50'));
});
