<?php

use App\Game\Combat\PvpAttackService;
use App\Game\Data\AttackTarget;
use App\Game\Enums\AttackRefusalReason;
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
    $event = $service->attack($service->findTarget('OFFENSIVE'), message: 'gg');

    expect($event->kind)->toBe(BattleKind::Pvp)
        ->and($event->outcome)->toBe(BattleOutcome::Win)
        ->and($event->opponent_name)->toBe('OFFENSIVE')
        ->and($event->battle_id)->toBe(808)
        ->and($event->exp_gained)->toBe(40);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'somethingelse.php')
        && str_contains($request->url(), 'attackid=302')
        && str_contains($request->body(), 'hash=5648d8cd')
        && str_contains($request->body(), 'rage=500'));
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

it('sends the rage cost the server supplied for that target, not a number of our own', function () {
    // VERIFIED 2026-08-22: `rage` is a hidden field pre-filled by
    // showAttackWindow with the attack's cost — it was never a 2-50 slider,
    // and inventing a value would misstate the cost to the game.
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*playersearch.php*' => Http::response(gameFixture('playersearch_results.html')),
        '*somethingelse.php*' => Http::response('', 302, ['Location' => '/plrattack/1/']),
        '*plrattack/1*' => Http::response('var battle_result = "x has gained 1 experience!";'),
    ]);

    $service = PvpAttackService::forCharacter($character);
    $target = $service->findTarget('OFFENSIVE');

    expect($target->rageCost)->toBe(500);

    $service->attack($target);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'somethingelse.php')
        && str_contains($request->body(), 'rage=500'));
});

it('records the opponent player id, because names change but ids do not', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*playersearch.php*' => Http::response(gameFixture('playersearch_results.html')),
        '*somethingelse.php*' => Http::response('', 302, ['Location' => '/plrattack/808/']),
        '*plrattack/808*' => Http::response('var battle_result = "OFFENSIVE has gained 40 experience!";'),
    ]);

    $service = PvpAttackService::forCharacter($character);
    $event = $service->attack($service->findTarget('OFFENSIVE'));

    expect($event->opponent_player_id)->toBe(302)
        ->and($event->opponent_level)->toBe(71);
});

it('classifies a cooldown refusal and exposes when the target frees up', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*playersearch.php*' => Http::response(gameFixture('playersearch_results.html')),
        '*somethingelse.php*' => Http::response(gameFixture('pvp_attack_cooldown_refusal.html')),
    ]);

    $service = PvpAttackService::forCharacter($character);
    $event = $service->attack($service->findTarget('OFFENSIVE'));

    expect($event->outcome)->toBe(BattleOutcome::Failed)
        ->and($event->fail_reason)->toContain('free in 57m');

    expect($service->lastRefusal()?->reason)->toBe(AttackRefusalReason::Cooldown)
        ->and($service->lastRefusal()?->retryInMinutes())->toBe(57);
});

it('mints a fresh hash for a target that arrived without one', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*playersearch.php*' => Http::response(gameFixture('playersearch_results.html'))]);

    // Crew rosters and brawl standings render no attack icon.
    $hashless = new AttackTarget(playerId: 302, name: 'OFFENSIVE');

    expect($hashless->isReadyToAttack())->toBeFalse();

    $resolved = PvpAttackService::forCharacter($character)->refreshHash($hashless);

    expect($resolved?->hash)->toBe('5648d8cd')
        ->and($resolved?->isReadyToAttack())->toBeTrue();
});
