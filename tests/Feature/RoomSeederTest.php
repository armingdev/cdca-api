<?php

use App\Models\Room;
use Database\Seeders\RoomSeeder;

it('seeds the full room graph with nulled non-exits', function () {
    (new RoomSeeder)->run();

    // 41,055 seed rows, 30 duplicate ids → 41,025 rooms.
    expect(Room::count())->toBe(41025);

    $room = Room::find(3);
    expect($room->name)->toBe('Shadow Path')
        ->and($room->east)->toBe(4)
        ->and($room->north)->toBeNull()
        ->and($room->south)->toBeNull()
        ->and($room->west)->toBeNull()
        ->and($room->source)->toBe('seed');

    // 695 seed rows carry an empty name; 30 of them lose the duplicate-id
    // merge → 665 nameless rooms stored as null.
    expect(Room::whereNull('name')->count())->toBe(665);
});

it('resolves duplicate seed ids to the more informative row', function () {
    (new RoomSeeder)->run();

    // Id 28040 appears twice: nameless with two exits vs "Infinite Tower"
    // with one — the named row wins.
    $room = Room::find(28040);
    expect($room->name)->toBe('Infinite Tower')
        ->and($room->north)->toBe(28041)
        ->and($room->south)->toBeNull();
});

it('never overwrites rooms the spider has visited', function () {
    Room::factory()->create([
        'id' => 3,
        'name' => 'Renamed by Spider',
        'east' => 99,
        'source' => 'spider',
    ]);

    (new RoomSeeder)->run();

    $room = Room::find(3);
    expect($room->name)->toBe('Renamed by Spider')
        ->and($room->east)->toBe(99)
        ->and($room->source)->toBe('spider');
});

it('is idempotent', function () {
    (new RoomSeeder)->run();
    (new RoomSeeder)->run();

    expect(Room::count())->toBe(41025);
});
