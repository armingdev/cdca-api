<?php

use App\Game\Enums\TeleportKind;
use App\Game\World\RoomGraph;
use App\Game\World\TeleportPlanner;
use App\Models\TeleportAnchor;

/**
 * A 1..10 corridor plus an isolated pocket (90 ↔ 91) that no walk reaches.
 */
function corridor(): RoomGraph
{
    $adjacency = [];

    foreach (range(1, 10) as $room) {
        $adjacency[$room] = array_filter([
            'west' => $room > 1 ? $room - 1 : null,
            'east' => $room < 10 ? $room + 1 : null,
        ]);
    }

    $adjacency[90] = ['east' => 91];
    $adjacency[91] = ['west' => 90];

    return new RoomGraph($adjacency);
}

function itemAnchor(int $roomId): TeleportAnchor
{
    return new TeleportAnchor([
        'kind' => TeleportKind::Item,
        'name' => "Anchor {$roomId}",
        'room_id' => $roomId,
        'rage_cost' => 0,
        'cooldown_minutes' => 0,
    ]);
}

function skillAnchor(int $roomId): TeleportAnchor
{
    return new TeleportAnchor([
        'kind' => TeleportKind::Skill,
        'skill_id' => 27,
        'name' => "Destination {$roomId}",
        'room_id' => $roomId,
        'rage_cost' => 100,
        'cooldown_minutes' => 60,
    ]);
}

it('walks when there is no teleport to take', function () {
    $plan = new TeleportPlanner(corridor())->plan(1, 4, []);

    expect($plan->usesTeleport())->toBeFalse()
        ->and($plan->walkPath)->toBe([1, 2, 3, 4])
        ->and($plan->cost())->toBe(3);
});

it('takes a free item jump that shortens the walk', function () {
    $plan = new TeleportPlanner(corridor())->plan(1, 10, [itemAnchor(9)]);

    expect($plan->anchor->room_id)->toBe(9)
        ->and($plan->walkPath)->toBe([9, 10])
        ->and($plan->cost())->toBe(2);
});

it('ignores an item jump that does not beat walking', function () {
    $plan = new TeleportPlanner(corridor())->plan(1, 2, [itemAnchor(9)]);

    expect($plan->usesTeleport())->toBeFalse()
        ->and($plan->walkPath)->toBe([1, 2]);
});

it('reaches a pocket the walk graph cannot', function () {
    $plan = new TeleportPlanner(corridor())->plan(1, 91, [itemAnchor(90)]);

    expect($plan->anchor->room_id)->toBe(90)
        ->and($plan->walkPath)->toBe([90, 91]);
});

it('returns null when neither walking nor any anchor reaches the target', function () {
    $plan = new TeleportPlanner(corridor())->plan(1, 91, [itemAnchor(5)]);

    expect($plan)->toBeNull();
});

it('ignores anchors whose landing room was never observed', function () {
    $unknown = itemAnchor(9);
    $unknown->room_id = null;

    $plan = new TeleportPlanner(corridor())->plan(1, 10, [$unknown]);

    expect($plan->usesTeleport())->toBeFalse()
        ->and($plan->cost())->toBe(9);
});

it('spends the skill cooldown only when it saves enough walking', function () {
    // Walking 1 → 10 costs 9 steps; the jump would cost 2. A threshold of 50
    // rejects it, a threshold of 5 accepts it.
    $stingy = new TeleportPlanner(corridor())->plan(1, 10, [skillAnchor(9)]);
    $eager = new TeleportPlanner(corridor(), skillSavingsThreshold: 5)->plan(1, 10, [skillAnchor(9)]);

    expect($stingy->usesTeleport())->toBeFalse()
        ->and($eager->anchor->room_id)->toBe(9);
});

it('uses the skill when nothing else reaches the target at all', function () {
    $plan = new TeleportPlanner(corridor())->plan(1, 91, [skillAnchor(90)]);

    expect($plan->anchor->kind)->toBe(TeleportKind::Skill)
        ->and($plan->walkPath)->toBe([90, 91]);
});

it('prefers a free item over the skill when both land equally well', function () {
    $plan = new TeleportPlanner(corridor(), skillSavingsThreshold: 0)
        ->plan(1, 10, [skillAnchor(9), itemAnchor(9)]);

    expect($plan->anchor->kind)->toBe(TeleportKind::Item);
});

it('takes the free home tavern when it beats walking', function () {
    $plan = new TeleportPlanner(corridor())->plan(1, 10, [], homeTavernRoomId: 9);

    expect($plan->useHomeTavern)->toBeTrue()
        ->and($plan->anchor)->toBeNull()
        ->and($plan->walkPath)->toBe([9, 10]);
});
