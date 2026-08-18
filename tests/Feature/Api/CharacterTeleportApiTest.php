<?php

use App\Models\Character;
use App\Models\CharacterTeleportAnchor;
use App\Models\Rga;
use App\Models\Room;
use App\Models\TeleportAnchor;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
    $this->character = Character::factory()->for($this->rga)->create();
});

function linkAnchor(Character $character, TeleportAnchor $anchor, int $iid = 42, bool $available = true): CharacterTeleportAnchor
{
    return CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => $iid,
        'is_available' => $available,
    ]);
}

function roomBlobJson(int $roomId, string $name, array $exits = []): string
{
    return json_encode([
        'error' => '', 'curRoom' => (string) $roomId, 'name' => $name,
        'north' => (string) ($exits['north'] ?? 0), 'east' => (string) ($exits['east'] ?? 0),
        'south' => (string) ($exits['south'] ?? 0), 'west' => (string) ($exits['west'] ?? 0),
        'roomDetailsNew' => [], 'doorsData' => null,
    ]);
}

it('lists the character\'s anchors', function () {
    $room = Room::factory()->create(['name' => 'Astral Rift']);
    $anchor = TeleportAnchor::factory()->create(['name' => 'Astral Ward', 'room_id' => $room->id]);
    linkAnchor($this->character, $anchor);

    $this->getJson("/api/v1/characters/{$this->character->id}/teleports")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Astral Ward')
        ->assertJsonPath('data.0.room_name', 'Astral Rift')
        ->assertJsonPath('data.0.free', true)
        ->assertJsonPath('data.0.destination_known', true)
        ->assertJsonPath('data.0.available', true);
});

it('forbids reading another user\'s teleports', function () {
    $other = Character::factory()->for(Rga::factory()->for(User::factory()))->create();

    $this->getJson("/api/v1/characters/{$other->id}/teleports")->assertForbidden();
});

it('syncs anchors from the game', function () {
    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
    ]);

    $this->postJson("/api/v1/characters/{$this->character->id}/teleports/sync")
        ->assertOk()
        ->assertJsonPath('item_anchors', 28)
        ->assertJsonPath('skill_anchors', 0)
        ->assertJsonPath('without_destination', 28)
        ->assertJsonCount(28, 'anchors');
});

it('jumps with a named anchor', function () {
    $anchor = TeleportAnchor::factory()->create(['name' => 'Astral Ward', 'room_id' => 26137]);
    linkAnchor($this->character, $anchor, iid: 340588211);

    Http::fake([
        '*backpack_action.php*' => Http::response('{"status":"Astral Ward activated!<br>"}'),
        '*ajax_changeroomb.php*' => Http::response(roomBlobJson(26137, 'Astral Rift')),
    ]);

    $this->postJson("/api/v1/characters/{$this->character->id}/teleports", ['anchor_id' => $anchor->id])
        ->assertOk()
        ->assertJsonPath('room_id', 26137)
        ->assertJsonPath('teleported', true)
        ->assertJsonPath('steps_walked', 0);
});

it('plans a jump plus a walk to reach a room', function () {
    Room::factory()->create(['id' => 1]);
    Room::factory()->create(['id' => 500, 'east' => 501]);
    Room::factory()->create(['id' => 501, 'west' => 500]);

    $anchor = TeleportAnchor::factory()->create(['name' => 'Astral Ward', 'room_id' => 500]);
    linkAnchor($this->character, $anchor);

    Http::fake([
        '*backpack_action.php*' => Http::response('{"status":"Astral Ward activated!<br>"}'),
        '*ajax_changeroomb.php*' => Http::sequence()
            ->push(roomBlobJson(1, 'Start'))
            ->push(roomBlobJson(500, 'Landing', ['east' => 501]))
            ->push(roomBlobJson(501, 'Target', ['west' => 500])),
    ]);

    $this->postJson("/api/v1/characters/{$this->character->id}/teleports", ['room_id' => 501])
        ->assertOk()
        ->assertJsonPath('room_id', 501)
        ->assertJsonPath('teleported', true)
        ->assertJsonPath('anchor', 'Astral Ward')
        ->assertJsonPath('steps_walked', 1);
});

it('rejects a destination nothing can reach', function () {
    Room::factory()->create(['id' => 1]);

    Http::fake(['*ajax_changeroomb.php*' => Http::response(roomBlobJson(1, 'Start'))]);

    $this->postJson("/api/v1/characters/{$this->character->id}/teleports", ['room_id' => 999])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No route from room 1 to room 999, with or without a teleport.');
});

it('rejects an empty teleport request', function () {
    $this->postJson("/api/v1/characters/{$this->character->id}/teleports", [])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('room_id');
});

it('reports a game refusal as a 422', function () {
    $anchor = TeleportAnchor::factory()->create(['name' => 'Locked Key']);
    linkAnchor($this->character, $anchor, available: false);

    Http::fake();

    $this->postJson("/api/v1/characters/{$this->character->id}/teleports", ['anchor_id' => $anchor->id])
        ->assertStatus(422)
        ->assertJsonPath('message', "Character {$this->character->name} cannot use teleport anchor Locked Key.");
});

it('sets the home tavern', function () {
    Http::fake(['*world.php*' => Http::response('')]);

    $this->postJson("/api/v1/characters/{$this->character->id}/home-tavern", ['room_id' => 376])
        ->assertOk()
        ->assertJsonPath('home_tavern_room_id', 376);

    expect($this->character->fresh()->home_tavern_room_id)->toBe(376);
});

it('returns to the home tavern', function () {
    Http::fake([
        '*world.php*' => Http::response(''),
        '*ajax_changeroomb.php*' => Http::response(roomBlobJson(376, 'The Drunken Clam')),
    ]);

    $this->postJson("/api/v1/characters/{$this->character->id}/teleports", ['home_tavern' => true])
        ->assertOk()
        ->assertJsonPath('room_id', 376)
        ->assertJsonPath('teleported', true);

    expect($this->character->fresh()->home_tavern_room_id)->toBe(376);
});
