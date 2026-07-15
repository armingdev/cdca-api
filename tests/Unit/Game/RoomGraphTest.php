<?php

use App\Game\World\RoomGraph;

function lineGraph(): RoomGraph
{
    // 1 –E– 2 –E– 3, with 2 also opening south to 5.
    return new RoomGraph([
        1 => ['east' => 2],
        2 => ['west' => 1, 'east' => 3, 'south' => 5],
        3 => ['west' => 2],
        5 => ['north' => 2],
    ]);
}

it('finds the shortest path between rooms across the graph', function () {
    expect(lineGraph()->shortestPath(1, 5))->toBe([1, 2, 5])
        ->and(lineGraph()->shortestPath(3, 5))->toBe([3, 2, 5])
        ->and(lineGraph()->shortestPath(2, 2))->toBe([2]);
});

it('returns null when no path exists', function () {
    $graph = new RoomGraph([
        1 => ['east' => 2],
        2 => ['west' => 1],
        9 => [],
    ]);

    expect($graph->shortestPath(1, 9))->toBeNull();
});

it('respects directed edges and never assumes symmetry', function () {
    // One-way passage: 1 → 2, but 2 has no exit back.
    $graph = new RoomGraph([
        1 => ['east' => 2],
        2 => [],
    ]);

    expect($graph->shortestPath(1, 2))->toBe([1, 2])
        ->and($graph->shortestPath(2, 1))->toBeNull();
});

it('routes to the nearest room matching a predicate, including unknown frontier rooms', function () {
    // Room 3's east neighbor 4 has never been loaded (not a key in the graph).
    $graph = new RoomGraph([
        1 => ['east' => 2],
        2 => ['west' => 1, 'east' => 3],
        3 => ['west' => 2, 'east' => 4],
    ]);

    $path = $graph->pathToNearest(1, fn (int $roomId): bool => ! $graph->has($roomId));

    expect($path)->toBe([1, 2, 3, 4]);
});

it('grows as rooms are added', function () {
    $graph = new RoomGraph;

    expect($graph->count())->toBe(0);

    $graph->addRoom(11, ['north' => 12, 'south' => 40]);

    expect($graph->has(11))->toBeTrue()
        ->and($graph->neighbors(11))->toBe(['north' => 12, 'south' => 40])
        ->and($graph->count())->toBe(1);
});
