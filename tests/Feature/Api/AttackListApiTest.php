<?php

use App\Models\AttackList;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('creates, lists, and shows attack lists scoped to the user', function () {
    $this->postJson('/api/v1/attack-lists', ['name' => 'Rivals'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Rivals');

    AttackList::factory()->for(User::factory())->create(); // someone else's

    $this->getJson('/api/v1/attack-lists')->assertOk()->assertJsonCount(1, 'data');
});

it('adds targets by name, which is what the user knows', function () {
    $list = AttackList::factory()->for($this->user)->create();

    $this->postJson("/api/v1/attack-lists/{$list->id}/targets", ['name' => 'Krongstein'])
        ->assertOk()
        ->assertJsonPath('data.targets.0.name', 'Krongstein')
        ->assertJsonPath('data.targets.0.position', 1)
        ->assertJsonPath('data.targets.0.player_id', null);
});

it('removes a target and closes the gap', function () {
    $list = AttackList::factory()->for($this->user)->create();
    $list->addTarget('One');
    $list->addTarget('Two');

    $this->deleteJson("/api/v1/attack-lists/{$list->id}/targets/1")
        ->assertOk()
        ->assertJsonPath('data.targets.0.name', 'Two')
        ->assertJsonPath('data.targets.0.position', 1);
});

it('404s removing a position that does not exist', function () {
    $list = AttackList::factory()->for($this->user)->create();

    $this->deleteJson("/api/v1/attack-lists/{$list->id}/targets/7")->assertNotFound();
});

it('refuses access to another user\'s attack list', function () {
    $theirs = AttackList::factory()->for(User::factory())->create();

    $this->getJson("/api/v1/attack-lists/{$theirs->id}")->assertForbidden();
    $this->postJson("/api/v1/attack-lists/{$theirs->id}/targets", ['name' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/attack-lists/{$theirs->id}")->assertForbidden();
});

it('lets two users each keep a list of the same name', function () {
    AttackList::factory()->for(User::factory())->create(['name' => 'Rivals']);

    $this->postJson('/api/v1/attack-lists', ['name' => 'Rivals'])->assertCreated();
});

it('rejects a duplicate list name for the same user', function () {
    AttackList::factory()->for($this->user)->create(['name' => 'Rivals']);

    $this->postJson('/api/v1/attack-lists', ['name' => 'Rivals'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('starts a pvp run from a saved attack list', function () {
    $list = AttackList::factory()->for($this->user)->create();
    $list->addTarget('Krongstein');

    $rga = Rga::factory()->for($this->user)->create();
    $character = Character::factory()->for($rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'pvp-attack-list',
        'characters' => [$character->id],
        'attack_list_id' => $list->id,
        'restart_every_minutes' => 60,
    ])->assertCreated()->assertJsonPath('data.mode', 'pvp-attack-list');

    expect(Run::first()->config['attack_list_id'])->toBe($list->id);
});

it('refuses to start a run from someone else\'s attack list', function () {
    $theirs = AttackList::factory()->for(User::factory())->create();

    $rga = Rga::factory()->for($this->user)->create();
    $character = Character::factory()->for($rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'pvp-attack-list',
        'characters' => [$character->id],
        'attack_list_id' => $theirs->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('attack_list_id');
});

it('echoes back only the config keys the mode can use', function (string $mode, array $absent, array $present) {
    $rga = Rga::factory()->for($this->user)->create();
    $character = Character::factory()->for($rga)->create();

    $payload = [
        'mode' => $mode,
        'characters' => [$character->id],
        'targets' => ['Someone'],
        'crew_game_id' => 8698,
        'auto_enter_brawl' => true,
    ];

    $this->postJson('/api/v1/runs', $payload)->assertCreated();

    $config = Run::latest('id')->first()->config;

    foreach ($absent as $key) {
        expect($config)->not->toHaveKey($key);
    }

    foreach ($present as $key) {
        expect($config)->toHaveKey($key);
    }
})->with([
    // A crew-hitlist run carrying auto_enter_brawl invites the client to
    // render a control that does nothing.
    'crew hitlist' => ['pvp-crew-hitlist', ['auto_enter_brawl', 'targets', 'crew_game_id'], ['attacks_per_target']],
    'crew members' => ['pvp-crew-members', ['auto_enter_brawl', 'targets'], ['crew_game_id']],
    'attack list' => ['pvp-attack-list', ['auto_enter_brawl', 'crew_game_id'], ['targets']],
    'brawl' => ['pvp-brawl', ['targets', 'crew_game_id'], ['auto_enter_brawl']],
]);
