<?php

use App\Game\Exceptions\DesyncException;
use App\Game\Exceptions\GatedRoomException;
use App\Game\World\Navigator;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Room;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function roomJson(int $roomId, array $exits = [], string $error = ''): string
{
    return json_encode([
        'error' => $error,
        'curRoom' => (string) $roomId,
        'name' => "Room {$roomId}",
        'north' => (string) ($exits['north'] ?? 0),
        'east' => (string) ($exits['east'] ?? 0),
        'south' => (string) ($exits['south'] ?? 0),
        'west' => (string) ($exits['west'] ?? 0),
        'roomDetailsNew' => [],
        'doorsData' => null,
    ]);
}

it('steps to a neighbor, records the room, and tracks the character position', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*ajax_changeroomb.php*' => Http::response(roomJson(31955, ['east' => 31954])),
    ]);

    $blob = Navigator::forCharacter($character)->stepTo(31955, 31954);

    expect($blob->curRoom)->toBe(31955)
        ->and($character->fresh()->current_room_id)->toBe(31955)
        ->and(Room::find(31955)->east)->toBe(31954);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'room=31955')
        && str_contains($request->url(), 'lastroom=31954'));
});

it('throws a desync when the game reports a different room', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*ajax_changeroomb.php*' => Http::response(roomJson(100)),
    ]);

    Navigator::forCharacter($character)->stepTo(31955, 31954);
})->throws(DesyncException::class);

it('throws a gated-room exception when entry is refused', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*ajax_changeroomb.php*' => Http::response(json_encode([
            'error' => 'You must be carrying a torch to enter this room.',
        ])),
    ]);

    Navigator::forCharacter($character)->stepTo(999, 998);
})->throws(GatedRoomException::class, 'torch');

it('resets to the start room via world?room=1', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*world*' => Http::response('<html>world page</html>'),
        '*ajax_changeroomb.php*' => Http::response(roomJson(1, ['east' => 2])),
    ]);

    $blob = Navigator::forCharacter($character)->resetToStart();

    expect($blob->curRoom)->toBe(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'world?room=1'));
});
