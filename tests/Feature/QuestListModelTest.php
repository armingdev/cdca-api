<?php

use App\Models\QuestList;

it('appends quests in order and exposes a display name', function () {
    $list = QuestList::create(['name' => 'Armins List']);

    $a = $list->addQuest(742, 'Stella', 'Street Crawler');
    $b = $list->addQuest(743, 'Stella');

    expect($a->position)->toBe(1)
        ->and($b->position)->toBe(2)
        ->and($a->displayName())->toBe('Street Crawler')
        ->and($b->displayName())->toBe('Quest 743')
        ->and($list->items()->pluck('quest_id')->all())->toBe([742, 743]);
});

it('removes an item and closes the position gap', function () {
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest(1, 'A');
    $list->addQuest(2, 'B');
    $list->addQuest(3, 'C');

    expect($list->removePosition(2))->toBeTrue()
        ->and($list->items()->pluck('position')->all())->toBe([1, 2])
        ->and($list->items()->pluck('quest_id')->all())->toBe([1, 3]);
});

it('reports failure removing a non-existent position', function () {
    $list = QuestList::create(['name' => 'Empty']);

    expect($list->removePosition(5))->toBeFalse();
});
