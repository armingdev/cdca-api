<?php

use App\Models\QuestList;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('creates, lists, and shows quest lists scoped to the user', function () {
    $this->postJson('/api/v1/quest-lists', ['name' => 'Armins List'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Armins List');

    QuestList::factory()->for(User::factory())->create(); // someone else's

    $this->getJson('/api/v1/quest-lists')->assertOk()->assertJsonCount(1, 'data');
});

it('adds and removes quests, keeping positions contiguous', function () {
    $list = QuestList::factory()->for($this->user)->create();

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => 742, 'npc_name' => 'Stella', 'label' => 'Street Crawler'])
        ->assertOk()
        ->assertJsonPath('data.items.0.quest_id', 742);

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => 743, 'npc_name' => 'Stella'])
        ->assertOk()
        ->assertJsonCount(2, 'data.items');

    $this->deleteJson("/api/v1/quest-lists/{$list->id}/items/1")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quest_id', 743)
        ->assertJsonPath('data.items.0.position', 1);
});

it('forbids touching another user\'s quest list', function () {
    $other = QuestList::factory()->for(User::factory())->create();

    $this->getJson("/api/v1/quest-lists/{$other->id}")->assertForbidden();
    $this->postJson("/api/v1/quest-lists/{$other->id}/items", ['quest_id' => 1, 'npc_name' => 'X'])->assertForbidden();
});
