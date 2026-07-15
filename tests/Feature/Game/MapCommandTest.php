<?php

use App\Models\Character;
use App\Models\Rga;
use App\Models\Room;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

/**
 * A tiny fake world:
 *
 *   1 –E– 2 –E– 3 (dead end)
 *         |S
 *         5 –E– 9 (gated)
 *
 * Mapping it exercises stepping, dead-end backtracking via BFS-to-frontier,
 * and gated-room handling.
 */
function fakeWorld(): array
{
    return [
        1 => ['east' => 2],
        2 => ['west' => 1, 'east' => 3, 'south' => 5],
        3 => ['west' => 2],
        5 => ['north' => 2, 'east' => 9],
    ];
}

function fakeWorldResponses(): void
{
    $position = 1;

    Http::fake(function ($request) use (&$position) {
        if (! str_contains($request->url(), 'ajax_changeroomb.php')) {
            return Http::response('<html>world page</html>');
        }

        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $target = (int) $query['room'];

        if ($target === 0) {
            $target = $position;
        }

        if ($target === 9) {
            return Http::response(json_encode([
                'error' => 'You must be carrying a key to enter this room.',
            ]));
        }

        $position = $target;
        $exits = fakeWorld()[$target];

        return Http::response(json_encode([
            'error' => '',
            'curRoom' => (string) $target,
            'name' => "Room {$target}",
            'north' => (string) ($exits['north'] ?? 0),
            'east' => (string) ($exits['east'] ?? 0),
            'south' => (string) ($exits['south'] ?? 0),
            'west' => (string) ($exits['west'] ?? 0),
            'roomDetailsNew' => [],
            'doorsData' => null,
        ]));
    });
}

it('maps the whole reachable component, backtracking past dead ends and skipping gated rooms', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeWorldResponses();

    $this->artisan('outwar:map', ['character' => $character->id])
        ->assertSuccessful()
        ->expectsOutputToContain('fully mapped');

    expect(Room::count())->toBe(5)
        ->and(Room::find(2)->exits())->toBe(['east' => 3, 'south' => 5, 'west' => 1])
        ->and(Room::find(3)->last_verified_at)->not->toBeNull()
        ->and(Room::find(5)->east)->toBe(9)
        ->and(Room::find(9)->is_gated)->toBeTrue()
        ->and(Room::find(9)->gate_reason)->toContain('key')
        ->and($character->fresh()->current_room_id)->toBe(5);
});

it('resumes from persisted state without rewalking verified rooms', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeWorldResponses();

    // First run maps everything.
    $this->artisan('outwar:map', ['character' => $character->id])->assertSuccessful();

    // Second run: nothing left to explore — it should finish almost immediately.
    $this->artisan('outwar:map', ['character' => $character->id])
        ->assertSuccessful()
        ->expectsOutputToContain('fully mapped');

    expect(Room::count())->toBe(5);
});

it('honors the max-rooms bound', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeWorldResponses();

    $this->artisan('outwar:map', ['character' => $character->id, '--max-rooms' => 2])
        ->assertSuccessful();

    expect(Room::count())->toBeLessThanOrEqual(3);
});
