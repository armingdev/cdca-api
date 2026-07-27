<?php

use App\Models\Quest;
use App\Models\QuestList;

it('creates a quest list', function () {
    $this->artisan('outwar:questlist', ['action' => 'create', 'name' => 'Armins List'])
        ->assertSuccessful()
        ->expectsOutputToContain("Created quest list 'Armins List'");

    expect(QuestList::where('name', 'Armins List')->exists())->toBeTrue();
});

it('rejects creating a duplicate list', function () {
    QuestList::create(['name' => 'Armins List']);

    $this->artisan('outwar:questlist', ['action' => 'create', 'name' => 'Armins List'])
        ->assertFailed()
        ->expectsOutputToContain('already exists');
});

it('adds a quest to a list by game quest id', function () {
    QuestList::create(['name' => 'Armins List']);
    $quest = Quest::factory()->create(['game_quest_id' => 742, 'name' => 'Street Crawler', 'giver' => 'Stella']);

    $this->artisan('outwar:questlist', [
        'action' => 'add',
        'name' => 'Armins List',
        '--quest' => 742,
    ])->assertSuccessful()->expectsOutputToContain('Added Street Crawler (giver: Stella) at position 1');

    $item = QuestList::where('name', 'Armins List')->first()->items()->first();

    expect($item->quest_id)->toBe($quest->id)
        ->and($item->position)->toBe(1);
});

it('adds a quest to a list by exact name', function () {
    QuestList::create(['name' => 'Armins List']);
    Quest::factory()->create(['name' => 'Street Crawler', 'giver' => 'Stella']);

    $this->artisan('outwar:questlist', [
        'action' => 'add',
        'name' => 'Armins List',
        '--quest' => 'Street Crawler',
    ])->assertSuccessful()->expectsOutputToContain('Added Street Crawler');
});

it('rejects adding a quest missing from the catalog', function () {
    QuestList::create(['name' => 'Armins List']);

    $this->artisan('outwar:questlist', ['action' => 'add', 'name' => 'Armins List', '--quest' => 999])
        ->assertFailed()
        ->expectsOutputToContain('not in the catalog');
});

it('requires --quest when adding', function () {
    QuestList::create(['name' => 'Armins List']);

    $this->artisan('outwar:questlist', ['action' => 'add', 'name' => 'Armins List'])
        ->assertFailed()
        ->expectsOutputToContain('needs --quest');
});

it('shows a list with its quests', function () {
    $list = QuestList::create(['name' => 'Armins List']);
    $quest = Quest::factory()->create(['name' => 'Street Crawler', 'giver' => 'Stella']);
    $list->addQuest($quest->id);

    $this->artisan('outwar:questlist', ['action' => 'show', 'name' => 'Armins List'])
        ->assertSuccessful()
        ->expectsOutputToContain('Street Crawler');
});
