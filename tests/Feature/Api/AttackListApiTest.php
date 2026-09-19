<?php

use App\Game\Engine\PvpRunConfig;
use App\Models\AttackList;
use App\Models\Character;
use App\Models\Crew;
use App\Models\Rga;
use App\Models\Run;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
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

describe('crew-members runs', function () {
    it('stores several crews for one run', function () {
        Queue::fake();
        $character = Character::factory()->for(Rga::factory()->for($this->user))->create();

        $this->postJson('/api/v1/runs', [
            'mode' => 'pvp-crew-members',
            'characters' => [$character->id],
            'crew_game_ids' => [17785, 8698],
        ])
            ->assertCreated()
            ->assertJsonPath('data.config.crew_game_ids', [17785, 8698]);
    });

    it('still accepts the single crew id older clients send', function () {
        Queue::fake();
        $character = Character::factory()->for(Rga::factory()->for($this->user))->create();

        $this->postJson('/api/v1/runs', [
            'mode' => 'pvp-crew-members',
            'characters' => [$character->id],
            'crew_game_id' => 17785,
        ])
            ->assertCreated()
            ->assertJsonPath('data.config.crew_game_ids', [17785])
            ->assertJsonPath('data.config.crew_game_id', 17785);
    });

    it('returns 422 without any crew, with a repeated crew, or with more than ten', function (array $crews, string $field) {
        $character = Character::factory()->for(Rga::factory()->for($this->user))->create();

        $this->postJson('/api/v1/runs', [
            'mode' => 'pvp-crew-members',
            'characters' => [$character->id],
            'crew_game_ids' => $crews,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(Run::count())->toBe(0);
    })->with([
        'no crew' => [[], 'crew_game_ids'],
        'a repeated crew' => [[17785, 17785], 'crew_game_ids.0'],
        'eleven crews' => [range(1, 11), 'crew_game_ids'],
    ]);

    it('keeps running a run that was stored with a single crew id', function () {
        expect(PvpRunConfig::fromArray(['crew_game_id' => 17785])->crewGameIds)->toBe([17785]);
    });
});

describe('crew search', function () {
    it('finds crews by part of their name or by their game crew id', function () {
        $asylum = Crew::factory()->create(['name' => 'Asylum', 'game_crew_id' => 17785]);
        Crew::factory()->create(['name' => 'Krimson Tide', 'game_crew_id' => 8698]);

        $this->getJson('/api/v1/crews?search=syl')
            ->assertOk()
            ->assertJsonPath('data.*.game_crew_id', [17785])
            ->assertJsonPath('data.0.name', 'Asylum')
            ->assertJsonPath('data.0.id', $asylum->id);

        $this->getJson('/api/v1/crews?search=8698')->assertOk()->assertJsonPath('data.*.name', ['Krimson Tide']);
    });

    it('treats wildcard characters in the search as plain text', function () {
        Crew::factory()->create(['name' => 'Asylum']);

        $this->getJson('/api/v1/crews?search=%25')->assertOk()->assertJsonCount(0, 'data');
    });

    it('narrows crews to one server', function () {
        Crew::factory()->create(['name' => 'Sigil Crew']);
        Crew::factory()->torax()->create(['name' => 'Torax Crew']);

        $this->getJson('/api/v1/crews?server_id=2')->assertOk()->assertJsonPath('data.*.name', ['Torax Crew']);
    });

    it('returns 422 when asked for more than 100 crews per page', function () {
        $this->getJson('/api/v1/crews?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    });
});
