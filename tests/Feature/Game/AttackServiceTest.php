<?php

use App\Game\Combat\AttackService;
use App\Game\Data\MobSighting;
use App\Game\Enums\BattleOutcome;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function sighting(string $name = 'Pristine Blader'): MobSighting
{
    return MobSighting::fromArray([
        'name' => $name,
        'level' => '83',
        'rage' => '2880',
        'mobId' => '4387',
        'spawnId' => '247543',
        'h' => 'hash',
        'encid' => 'O330C342F360K348A342W336',
        'isDead' => false,
        'type' => 0,
        'canForm' => false,
    ]);
}

it('records a win with exp, gold, drop, and the resolved mob', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create(['current_room_id' => 31954]);
    $mob = Mob::factory()->create(['name' => 'Pristine Blader']);

    Http::fake([
        '*somethingelse.php*' => Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/20070546825/']),
        '*attack/20070546825*' => Http::response(
            gameFixture('battle_result_vars.js')
            .'<div id="found_items"><b>WIN: Found Thief Dagger</b></div>'
        ),
    ]);

    $event = AttackService::forCharacter($character)->attack(sighting());

    expect($event->outcome)->toBe(BattleOutcome::Win)
        ->and($event->battle_id)->toBe(20070546825)
        ->and($event->exp_gained)->toBe(1001)
        ->and($event->gold_gained)->toBe(125)
        ->and($event->drop_name)->toBe('Thief Dagger')
        ->and($event->mob_id)->toBe($mob->id)
        ->and($event->room_id)->toBe(31954);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'attackid=O330C342F360K348A342W336'));
});

it('records a failed attack when the game returns 200 with a reason', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*somethingelse.php*' => Http::response('<html><body>That mob is already dead!</body></html>'),
    ]);

    $event = AttackService::forCharacter($character)->attack(sighting());

    expect($event->outcome)->toBe(BattleOutcome::Failed)
        ->and($event->battle_id)->toBeNull()
        ->and($event->fail_reason)->toContain('already dead');
});

it('records a loss from the captured loss text', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*somethingelse.php*' => Http::response('', 302, ['Location' => '/attack/99/']),
        '*attack/99*' => Http::response('var battle_result = "Grand Sole Protector has weakened LinuXX_2 by 2";'),
    ]);

    $event = AttackService::forCharacter($character)->attack(sighting('Grand Sole Protector'));

    expect($event->outcome)->toBe(BattleOutcome::Loss)
        ->and($event->exp_gained)->toBeNull();
});
