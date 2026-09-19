<?php

use App\Game\Enums\RunSignal;
use App\Game\Exceptions\DesyncException;
use App\Game\Exceptions\GatedRoomException;
use App\Game\Exceptions\RunInterruptedException;
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

it('classifies a rejected move as a recoverable desync', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*ajax_changeroomb.php*' => Http::response(roomJson(0, [], 'Error moving rooms. Please click Explore in the menu and try again.')),
    ]);

    Navigator::forCharacter($character)->stepTo(12, 10);
})->throws(DesyncException::class);

it('teleports to a bar and reloads the room', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*world.php*' => Http::response('', 302, ['Location' => '/world']),
        '*ajax_changeroomb.php*' => Http::response(roomJson(258)),
    ]);

    $blob = Navigator::forCharacter($character)->teleportToBar();

    expect($blob->curRoom)->toBe(258);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'world.php')
        && str_contains($request->url(), 'teleport=1'));
});

it('abandons a walk between two steps when the run signals a stop', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(roomJson((int) $query['room']));
    });
    $navigator = Navigator::forCharacter($character);
    $stopRequested = false;
    $navigator->interruptWith(function () use (&$stopRequested): RunSignal {
        $signal = $stopRequested ? RunSignal::Stop : RunSignal::None;
        $stopRequested = true;

        return $signal;
    });

    expect(fn () => $navigator->walk([1, 2, 3, 4, 5]))
        ->toThrow(fn (RunInterruptedException $interrupt) => expect($interrupt->signal)->toBe(RunSignal::Stop));

    // Stood in rooms 2 and 3, never set off for 4.
    Http::assertSentCount(2);
    expect($character->fresh()->current_room_id)->toBe(3);
});
