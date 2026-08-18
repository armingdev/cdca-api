<?php

use App\Models\Character;
use App\Models\CharacterTeleportAnchor;
use App\Models\Rga;
use App\Models\Room;
use App\Models\TeleportAnchor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('syncs a character\'s anchors from the game', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
    ]);

    $this->artisan('outwar:teleports', ['action' => 'sync', 'character' => $character->name])
        ->expectsOutputToContain('28 anchors available')
        ->expectsOutputToContain('without a known landing room')
        ->assertSuccessful();

    expect(TeleportAnchor::count())->toBe(28);
});

it('lists what the character can teleport with', function () {
    $room = Room::factory()->create(['name' => 'Astral Rift']);
    $character = Character::factory()->for(Rga::factory()->withSession())->create([
        'home_tavern_room_id' => $room->id,
    ]);
    $anchor = TeleportAnchor::factory()->create(['name' => 'Astral Ward', 'room_id' => $room->id]);
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 340588211,
        'is_available' => true,
    ]);

    $this->artisan('outwar:teleports', ['action' => 'list', 'character' => $character->name])
        ->expectsOutputToContain('Astral Ward')
        ->expectsOutputToContain('Astral Rift')
        ->assertSuccessful();
});

it('discovers where undiscovered anchors land', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $anchor = TeleportAnchor::factory()->undiscovered()->create(['name' => 'Grove Insignia']);
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 1737783117,
        'is_available' => true,
    ]);

    Http::fake([
        '*backpack_action.php*' => Http::response('{"status":"Grove Insignia activated!<br>","redirectTo":"\/world"}'),
        '*ajax_changeroomb.php*' => Http::response(json_encode([
            'error' => '', 'curRoom' => '43283', 'name' => 'Twilight Grove',
            'north' => '0', 'east' => '0', 'south' => '0', 'west' => '0',
            'roomDetailsNew' => [], 'doorsData' => null,
        ])),
    ]);

    $this->artisan('outwar:teleports', ['action' => 'discover', 'character' => $character->name])
        ->expectsOutputToContain('Twilight Grove')
        ->assertSuccessful();

    expect($anchor->fresh()->room_id)->toBe(43283);
});

it('travels to a room by jumping and then walking', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    // 500 –east– 501, with an anchor landing in 500 and the character in 1.
    Room::factory()->create(['id' => 1]);
    Room::factory()->create(['id' => 500, 'east' => 501]);
    Room::factory()->create(['id' => 501, 'west' => 500]);

    $anchor = TeleportAnchor::factory()->create(['name' => 'Astral Ward', 'room_id' => 500]);
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 42,
        'is_available' => true,
    ]);

    $rooms = [
        json_encode(['error' => '', 'curRoom' => '1', 'name' => 'Start', 'north' => '0', 'east' => '0', 'south' => '0', 'west' => '0', 'roomDetailsNew' => [], 'doorsData' => null]),
        json_encode(['error' => '', 'curRoom' => '500', 'name' => 'Landing', 'north' => '0', 'east' => '501', 'south' => '0', 'west' => '0', 'roomDetailsNew' => [], 'doorsData' => null]),
        json_encode(['error' => '', 'curRoom' => '501', 'name' => 'Target', 'north' => '0', 'east' => '0', 'south' => '0', 'west' => '500', 'roomDetailsNew' => [], 'doorsData' => null]),
    ];

    Http::fake([
        '*backpack_action.php*' => Http::response('{"status":"Astral Ward activated!<br>"}'),
        '*ajax_changeroomb.php*' => Http::sequence()
            ->push($rooms[0])
            ->push($rooms[1])
            ->push($rooms[2]),
    ]);

    $this->artisan('outwar:teleports', ['action' => 'go', 'character' => $character->name, '--room' => 501])
        ->expectsOutputToContain('Teleporting with Astral Ward')
        ->expectsOutputToContain('Arrived in room 501')
        ->assertSuccessful();

    expect($character->fresh()->current_room_id)->toBe(501);
});

it('fails clearly when no route exists', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    Room::factory()->create(['id' => 1]);
    Room::factory()->create(['id' => 777]);

    Http::fake([
        '*ajax_changeroomb.php*' => Http::response(json_encode([
            'error' => '', 'curRoom' => '1', 'name' => 'Start',
            'north' => '0', 'east' => '0', 'south' => '0', 'west' => '0',
            'roomDetailsNew' => [], 'doorsData' => null,
        ])),
    ]);

    $this->artisan('outwar:teleports', ['action' => 'go', 'character' => $character->name, '--room' => 777])
        ->expectsOutputToContain('No route from 1 to 777')
        ->assertFailed();
});
