<?php

use App\Models\AttackList;
use App\Models\AttackListTarget;

it('appends targets in order', function () {
    $list = AttackList::factory()->create(['name' => 'Rivals']);

    $first = $list->addTarget('Krongstein', 265);
    $second = $list->addTarget('StarPower');

    expect($first->position)->toBe(1)
        ->and($second->position)->toBe(2)
        ->and($list->targets()->count())->toBe(2);
});

it('accepts a target by name alone, since that is what the user knows', function () {
    $list = AttackList::factory()->create();

    $target = $list->addTarget('StarPower');

    expect($target->player_id)->toBeNull()
        ->and($target->name)->toBe('StarPower');
});

it('closes the gap when a target is removed', function () {
    $list = AttackList::factory()->create();
    $list->addTarget('One');
    $list->addTarget('Two');
    $list->addTarget('Three');

    expect($list->removePosition(2))->toBeTrue();

    expect($list->targets()->pluck('name')->all())->toBe(['One', 'Three'])
        ->and($list->targets()->pluck('position')->all())->toBe([1, 2]);
});

it('reports removing a position that does not exist', function () {
    $list = AttackList::factory()->create();

    expect($list->removePosition(9))->toBeFalse();
});

it('cascades targets when the list is deleted', function () {
    $list = AttackList::factory()->create();
    $list->addTarget('One');

    $list->delete();

    expect(AttackListTarget::count())->toBe(0);
});
