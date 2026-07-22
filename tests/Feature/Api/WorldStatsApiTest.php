<?php

use App\Game\Enums\BattleKind;
use App\Game\Enums\BattleOutcome;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Rga;
use App\Models\Room;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->create();
});

it('shows a mapped room with its mobs', function () {
    $room = Room::factory()->create(['id' => 11, 'north' => 12, 'west' => 10]);
    Mob::factory()->create(['name' => 'Kix Harvester'])->rooms()->attach(11, ['last_seen_at' => now()]);

    $this->getJson('/api/v1/world/rooms/11')
        ->assertOk()
        ->assertJsonPath('data.id', 11)
        ->assertJsonPath('data.exits.north', 12)
        ->assertJsonPath('data.mobs.0.name', 'Kix Harvester');
});

it('searches mobs by name', function () {
    Mob::factory()->create(['name' => 'Kix Harvester']);
    Mob::factory()->create(['name' => 'Street Crawler']);

    $this->getJson('/api/v1/world/mobs?q=harvester')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Kix Harvester');
});

it('returns a character\'s aggregate battle stats', function () {
    $character = Character::factory()->for($this->rga)->create();
    $mob = Mob::factory()->create(['name' => 'Kix Harvester']);

    BattleEvent::factory()->for($character)->count(3)->create(['kind' => BattleKind::Pve, 'outcome' => BattleOutcome::Win, 'mob_id' => $mob->id, 'drop_name' => 'Kix Potion']);
    BattleEvent::factory()->for($character)->create(['kind' => BattleKind::Pve, 'outcome' => BattleOutcome::Loss, 'mob_id' => $mob->id, 'drop_name' => null]);

    $response = $this->getJson("/api/v1/characters/{$character->id}/stats")->assertOk();

    expect($response->json('mobs.0.name'))->toBe('Kix Harvester')
        ->and((int) $response->json('mobs.0.total'))->toBe(4)
        ->and((int) $response->json('mobs.0.wins'))->toBe(3)
        ->and((int) $response->json('mobs.0.losses'))->toBe(1)
        ->and((int) $response->json('drops.0.count'))->toBe(3);

    $this->getJson("/api/v1/characters/{$character->id}/battles")->assertOk()->assertJsonCount(4, 'data');
});
