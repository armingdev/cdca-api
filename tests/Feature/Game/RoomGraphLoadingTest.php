<?php

use App\Game\World\RoomGraph;
use App\Models\Room;
use Illuminate\Support\Facades\DB;

it('loads only the mapped exits of every room from the database', function () {
    Room::factory()->create(['id' => 10, 'east' => 11, 'south' => 12]);
    Room::factory()->create(['id' => 11, 'west' => 10]);
    Room::factory()->create(['id' => 12]);

    $graph = RoomGraph::fromDatabase();

    expect($graph->count())->toBe(3)
        ->and($graph->neighbors(10))->toBe(['east' => 11, 'south' => 12])
        ->and($graph->neighbors(11))->toBe(['west' => 10])
        ->and($graph->neighbors(12))->toBe([]);
});

it('builds the graph once for everything resolved inside the same scope', function () {
    Room::factory()->create(['id' => 10, 'east' => 11]);
    DB::enableQueryLog();

    $first = app(RoomGraph::class);
    $second = app(RoomGraph::class);

    expect($second)->toBe($first)
        ->and(DB::getQueryLog())->toHaveCount(1);
});

it('rebuilds the graph from the database for the next job', function () {
    Room::factory()->create(['id' => 10]);
    $stale = app(RoomGraph::class);
    Room::factory()->create(['id' => 11]);

    // What the queue worker does between two jobs.
    app()->forgetScopedInstances();

    expect($stale->has(11))->toBeFalse()
        ->and(app(RoomGraph::class)->has(11))->toBeTrue();
});
