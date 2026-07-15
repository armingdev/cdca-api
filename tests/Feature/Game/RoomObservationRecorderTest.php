<?php

use App\Game\Parsers\RoomBlobParser;
use App\Game\World\RoomObservationRecorder;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Room;
use App\Models\WorldChange;

function recorder(): RoomObservationRecorder
{
    return app(RoomObservationRecorder::class);
}

function blobFor(int $roomId, array $exits = [], array $mobs = [], string $name = 'Test Room')
{
    return app(RoomBlobParser::class)->parse(json_encode([
        'error' => '',
        'curRoom' => (string) $roomId,
        'name' => $name,
        'north' => (string) ($exits['north'] ?? 0),
        'east' => (string) ($exits['east'] ?? 0),
        'south' => (string) ($exits['south'] ?? 0),
        'west' => (string) ($exits['west'] ?? 0),
        'roomDetailsNew' => $mobs,
        'doorsData' => null,
    ]));
}

it('creates a room with exits and mob sightings on first observation', function () {
    $blob = blobFor(31954, ['west' => 31955], [[
        'name' => 'Gregov, Knight of the Woods',
        'level' => '120',
        'rage' => '400',
        'h' => 'abc',
        'encid' => 'ENC',
        'mobId' => '4387',
        'spawnId' => '247543',
        'image' => 'mobs/velgod2.jpg',
        'isDead' => false,
        'type' => 1,
        'lastKilledBy' => null,
        'canForm' => true,
    ]], 'Veldara Woods');

    $room = recorder()->record($blob);

    expect($room->id)->toBe(31954)
        ->and($room->name)->toBe('Veldara Woods')
        ->and($room->west)->toBe(31955)
        ->and($room->north)->toBeNull()
        ->and($room->first_seen_at)->not->toBeNull()
        ->and($room->last_verified_at)->not->toBeNull();

    $mob = Mob::where('name', 'Gregov, Knight of the Woods')->first();

    expect($mob)->not->toBeNull()
        ->and($mob->game_mob_id)->toBe(4387)
        ->and($mob->can_form)->toBeTrue()
        ->and($mob->rooms()->first()->id)->toBe(31954)
        ->and(WorldChange::count())->toBe(0);
});

it('journals topology drift when an exit changes', function () {
    recorder()->record(blobFor(7, ['east' => 8]));

    $character = Character::factory()->create();
    recorder()->record(blobFor(7, ['east' => 8, 'north' => 23332]), $character);

    $change = WorldChange::first();

    expect(WorldChange::count())->toBe(1)
        ->and($change->field)->toBe('north')
        ->and($change->old_value)->toBeNull()
        ->and($change->new_value)->toBe('23332')
        ->and($change->character_id)->toBe($character->id)
        ->and(Room::find(7)->north)->toBe(23332);
});

it('does not journal when nothing changed, and keeps first_seen_at stable', function () {
    $room = recorder()->record(blobFor(11, ['north' => 12]));
    $firstSeen = $room->first_seen_at;

    $this->travel(1)->hour();

    $room = recorder()->record(blobFor(11, ['north' => 12]));

    expect(WorldChange::count())->toBe(0)
        ->and($room->first_seen_at->equalTo($firstSeen))->toBeTrue()
        ->and($room->last_verified_at->greaterThan($firstSeen))->toBeTrue();
});

it('records gated rooms without exits and journals a later gate change', function () {
    $room = recorder()->recordGated(999, 'you must be carrying a key');

    expect($room->is_gated)->toBeTrue()
        ->and($room->gate_reason)->toBe('you must be carrying a key')
        ->and($room->exits())->toBe([]);

    // Entering the room later (e.g. with the key) clears the gate.
    $room = recorder()->record(blobFor(999, ['south' => 1000]));

    expect($room->is_gated)->toBeFalse()
        ->and($room->gate_reason)->toBeNull();
});
