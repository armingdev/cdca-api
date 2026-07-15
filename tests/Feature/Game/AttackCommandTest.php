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

    // Mapped world: room 1 –E– room 2; Kix Harvester lives in room 2.
    Room::factory()->create(['id' => 1, 'east' => 2]);
    Room::factory()->create(['id' => 2, 'west' => 1]);
    $mob = Mob::factory()->create(['name' => 'Kix Harvester']);
    $mob->rooms()->attach(2, ['last_seen_at' => now()]);
});

/**
 * Stateful fake game: the character starts in room 1, the harvester dies
 * after one successful attack, rage is configurable.
 */
function fakeCombatWorld(int $rage = 5000): void
{
    $position = 1;
    $killed = false;

    $roomBlob = function (int $roomId) use (&$killed): string {
        $mobs = $roomId === 2 ? [[
            'name' => 'Kix Harvester',
            'level' => '60',
            'rage' => '150',
            'h' => 'hash',
            'encid' => 'FRESH'.random_int(1, 9999),
            'mobId' => '777',
            'spawnId' => '1234',
            'image' => 'mobs/kix.jpg',
            'isDead' => $killed,
            'type' => 0,
            'lastKilledBy' => null,
            'canForm' => false,
        ]] : [];

        return json_encode([
            'error' => '',
            'curRoom' => (string) $roomId,
            'name' => "Room {$roomId}",
            'north' => '0',
            'east' => $roomId === 1 ? '2' : '0',
            'south' => '0',
            'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs,
            'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$killed, $roomBlob, $rage) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => number_format($rage), 'level' => '60', 'width' => 0]));
        }

        if (str_contains($url, 'somethingelse.php')) {
            $killed = true;

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/555/']);
        }

        if (str_contains($url, 'attack/555')) {
            return Http::response(
                'var battle_result = "Hero gained 2 strength<br>Hero has gained 950 experience!<br>Hero gained 55 gold!";'
                .'var attacker_name = "Hero"; var defender_name = "Kix Harvester";'
                .'<div id="found_items"><b>WIN: Found Kix Potion</b></div>'
            );
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $target = (int) $query['room'] ?: $position;
            $position = $target;

            return Http::response($roomBlob($target));
        }

        return Http::response('<html>world page</html>');
    });
}

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
