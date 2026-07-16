<?php

use App\Game\Enums\BattleOutcome;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Rga;
use App\Models\Room;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    // Mapped world: room 1 –E– room 2; Kix Harvester lives in room 2
    // (fake HTTP side + DB side live in tests/Pest.php).
    seedCombatWorld();
});

it('walks to the mob, kills it, records the battle and drop, then stops when no targets remain', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeCombatWorld();

    $this->artisan('outwar:attack', ['character' => $character->id, '--mob' => ['Kix Harvester']])
        ->assertSuccessful()
        ->expectsOutputToContain('found Kix Potion')
        ->expectsOutputToContain('Done: 1 wins / 0 losses / 0 errors.');

    $event = BattleEvent::first();

    expect(BattleEvent::count())->toBe(1)
        ->and($event->outcome)->toBe(BattleOutcome::Win)
        ->and($event->exp_gained)->toBe(950)
        ->and($event->drop_name)->toBe('Kix Potion')
        ->and($event->room_id)->toBe(2)
        ->and($event->mob_id)->toBe(Mob::where('name', 'Kix Harvester')->value('id'))
        ->and($character->fresh()->current_room_id)->toBe(2);
});

it('stops immediately at the rage floor without attacking', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeCombatWorld(rage: 1000);

    $this->artisan('outwar:attack', ['character' => $character->id, '--mob' => ['Kix Harvester'], '--stop-rage' => 2500])
        ->assertSuccessful()
        ->expectsOutputToContain('below the 2500 floor');

    expect(BattleEvent::count())->toBe(0);
});

it('fails fast when the mob has no known rooms', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $this->artisan('outwar:attack', ['character' => $character->id, '--mob' => ['Unknown Mob']])
        ->assertFailed()
        ->expectsOutputToContain('No known rooms');
});
