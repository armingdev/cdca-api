<?php

use App\Game\Enums\TeleportKind;
use App\Game\Exceptions\DesyncException;
use App\Game\Exceptions\GameException;
use App\Game\World\TeleportService;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\CharacterTeleportAnchor;
use App\Models\Rga;
use App\Models\Skill;
use App\Models\TeleportAnchor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function teleportCharacter(array $attributes = []): Character
{
    return Character::factory()->for(Rga::factory()->withSession())->create($attributes);
}

function teleportRoomJson(int $roomId, string $tavern = ''): string
{
    return json_encode([
        'error' => '',
        'curRoom' => (string) $roomId,
        'name' => "Room {$roomId}",
        'north' => '0', 'east' => '0', 'south' => '0', 'west' => '0',
        'roomDetailsNew' => [],
        'doorsData' => null,
        'tavernData' => $tavern,
    ]);
}

function trainTeleport(Character $character, array $state = []): CharacterSkill
{
    Skill::firstOrCreate(['id' => TeleportService::TELEPORT_SKILL_ID], [
        'name' => 'Teleport', 'school' => 'class',
        'rage_cost' => 100, 'cooldown_minutes' => 60, 'duration_minutes' => null,
    ]);

    return CharacterSkill::create([
        'character_id' => $character->id,
        'skill_id' => TeleportService::TELEPORT_SKILL_ID,
        'trained_level' => 1,
        'bonus_level' => 0,
        ...$state,
    ]);
}

it('syncs the key tab into anchors the character can use', function () {
    $character = teleportCharacter();

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
    ]);

    $result = TeleportService::forCharacter($character)->syncAnchors();

    expect($result->itemAnchors)->toBe(28)
        ->and($result->skillAnchors)->toBe(0)
        ->and($result->discovered)->toBe(28)
        ->and($result->withoutDestination)->toBe(28)
        ->and(TeleportAnchor::count())->toBe(28)
        ->and(CharacterTeleportAnchor::where('character_id', $character->id)->count())->toBe(28);

    $ward = TeleportAnchor::firstWhere('game_item_id', 4839);

    expect($ward->kind)->toBe(TeleportKind::Item)
        ->and($ward->name)->toBe('Astral Ward')
        ->and($ward->room_id)->toBeNull()
        ->and($ward->isFree())->toBeTrue()
        ->and($ward->characterAnchors()->first()->iid)->toBe(340588211);
});

it('does not catalogue carry-only gating keys', function () {
    $character = teleportCharacter();

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
    ]);

    TeleportService::forCharacter($character)->syncAnchors();

    expect(TeleportAnchor::where('name', 'Key to Kraw Village')->exists())->toBeFalse()
        ->and(TeleportAnchor::where('name', 'Battering Ram')->exists())->toBeFalse();
});

it('reuses catalog rows across characters and only re-reads the rollover once', function () {
    $first = teleportCharacter();
    $second = teleportCharacter();

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
    ]);

    TeleportService::forCharacter($first)->syncAnchors();
    $result = TeleportService::forCharacter($second)->syncAnchors();

    expect($result->discovered)->toBe(0)
        ->and(TeleportAnchor::count())->toBe(28)
        ->and(CharacterTeleportAnchor::count())->toBe(56);

    $rollovers = 0;
    Http::recorded(function ($request) use (&$rollovers) {
        $rollovers += str_contains($request->url(), 'item_rollover.php') ? 1 : 0;
    });

    expect($rollovers)->toBe(28);
});

it('flags anchors the character no longer holds as unavailable', function () {
    $character = teleportCharacter();
    $anchor = TeleportAnchor::factory()->create();
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 999,
        'is_available' => true,
    ]);

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
    ]);

    $result = TeleportService::forCharacter($character)->syncAnchors();

    expect($result->unavailable)->toBe(1)
        ->and(CharacterTeleportAnchor::where('teleport_anchor_id', $anchor->id)->first()->is_available)->toBeFalse();
});

it('syncs the skill destinations when Teleport is trained', function () {
    $character = teleportCharacter();
    trainTeleport($character);

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
        '*skills_info.php*' => Http::response(gameFixture('skills/skills_info_teleport.html')),
    ]);

    $result = TeleportService::forCharacter($character)->syncAnchors();

    expect($result->skillAnchors)->toBe(17)
        ->and($result->itemAnchors)->toBe(28);

    $sewers = TeleportAnchor::where('kind', TeleportKind::Skill)->where('room_id', 134)->first();

    expect($sewers->name)->toBe('Sewers Entrance')
        ->and($sewers->rage_cost)->toBe(100)
        ->and($sewers->cooldown_minutes)->toBe(60)
        ->and($sewers->isFree())->toBeFalse();
});

it('skips the skill destinations when Teleport is untrained', function () {
    $character = teleportCharacter();
    trainTeleport($character, ['trained_level' => 0, 'bonus_level' => 8, 'synced_at' => now()]);

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_key_tab.html')),
        '*item_rollover.php*' => Http::response(gameFixture('item_rollover_astral_ward.html')),
    ]);

    $result = TeleportService::forCharacter($character)->syncAnchors();

    expect($result->skillAnchors)->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'skills_info.php'));
});

it('activates an item and learns where it lands', function () {
    $character = teleportCharacter();
    $anchor = TeleportAnchor::factory()->undiscovered()->create(['name' => 'Astral Ward', 'game_item_id' => 4839]);
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 340588211,
        'is_available' => true,
    ]);

    Http::fake([
        '*backpack_action.php*' => Http::response('{"status":"Astral Ward activated!<br>","redirectTo":"\/world"}'),
        '*ajax_changeroomb.php*' => Http::response(teleportRoomJson(26137)),
    ]);

    $blob = TeleportService::forCharacter($character)->activateItem($anchor);

    expect($blob->curRoom)->toBe(26137)
        ->and($anchor->fresh()->room_id)->toBe(26137)
        ->and($character->fresh()->current_room_id)->toBe(26137);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        && $request['action'] === 'activate'
        && $request['itemids'] === ['340588211']);
});

it('does not follow the redirectTo the game offers', function () {
    $character = teleportCharacter();
    $anchor = TeleportAnchor::factory()->create();
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 1,
        'is_available' => true,
    ]);

    Http::fake([
        '*backpack_action.php*' => Http::response('{"status":"X activated!<br>","redirectTo":"\/world"}'),
        '*ajax_changeroomb.php*' => Http::response(teleportRoomJson(500)),
    ]);

    TeleportService::forCharacter($character)->activateItem($anchor);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/world'));
});

it('refuses to activate an anchor the character cannot use', function () {
    $character = teleportCharacter();
    $anchor = TeleportAnchor::factory()->create();
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 1,
        'is_available' => false,
    ]);

    Http::fake();

    expect(fn () => TeleportService::forCharacter($character)->activateItem($anchor))
        ->toThrow(GameException::class, 'cannot use teleport anchor');

    Http::assertNothingSent();
});

it('throws when the game does not confirm the activation', function () {
    $character = teleportCharacter();
    $anchor = TeleportAnchor::factory()->undiscovered()->create();
    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 1,
        'is_available' => true,
    ]);

    Http::fake(['*backpack_action.php*' => Http::response('{"status":"You are not high enough level."}')]);

    expect(fn () => TeleportService::forCharacter($character)->activateItem($anchor))
        ->toThrow(GameException::class, 'not confirmed');

    expect($anchor->fresh()->room_id)->toBeNull();
});

it('casts the teleport skill to a destination room', function () {
    $character = teleportCharacter(['rage' => 5000]);
    trainTeleport($character);

    Http::fake([
        '*cast_skills*' => Http::response('', 302, ['Location' => 'world.php']),
        '*ajax_changeroomb.php*' => Http::response(teleportRoomJson(376)),
    ]);

    $blob = TeleportService::forCharacter($character)->castTeleport(376);

    expect($blob->curRoom)->toBe(376)
        ->and(CharacterSkill::where('character_id', $character->id)->first()->last_cast_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'cast_skills')
        && $request['dest'] == 376
        && $request['castskillid'] == TeleportService::TELEPORT_SKILL_ID
        && $request['cast'] === 'Cast Skill');
});

it('refuses to cast teleport when it is untrained, on cooldown, or unaffordable', function (array $state, int $rage, string $message) {
    $character = teleportCharacter(['rage' => $rage]);

    if ($state !== []) {
        trainTeleport($character, $state);
    }

    Http::fake();

    expect(fn () => TeleportService::forCharacter($character)->castTeleport(376))
        ->toThrow(GameException::class, $message);

    Http::assertNothingSent();
})->with([
    'untrained' => [[], 5000, 'has not trained Teleport'],
    'on cooldown' => [['recharge_until' => now()->addMinutes(30)], 5000, 'on cooldown'],
    'not enough rage' => [['current_rage_cost' => 100], 40, 'needs 100 rage'],
]);

it('reports a desync when the teleport does not land where it was aimed', function () {
    $character = teleportCharacter(['rage' => 5000]);
    trainTeleport($character);

    Http::fake([
        '*cast_skills*' => Http::response('', 302, ['Location' => 'world.php']),
        '*ajax_changeroomb.php*' => Http::response(teleportRoomJson(258)),
    ]);

    expect(fn () => TeleportService::forCharacter($character)->castTeleport(376))
        ->toThrow(DesyncException::class);
});

it('learns the home tavern by returning to it', function () {
    $character = teleportCharacter();

    Http::fake([
        '*world.php*' => Http::response('', 302, ['Location' => '/world']),
        '*ajax_changeroomb.php*' => Http::response(teleportRoomJson(376, '<a href="/world.php?teleportupdate=1&tavern=376">Make The Drunken Clam my home!</a>')),
    ]);

    $blob = TeleportService::forCharacter($character)->toHomeTavern();

    expect($blob->tavernRoomId)->toBe(376)
        ->and($blob->isTavern())->toBeTrue()
        ->and($character->fresh()->home_tavern_room_id)->toBe(376);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'teleport=1'));
});

it('re-homes the tavern anchor', function () {
    $character = teleportCharacter();

    Http::fake(['*world.php*' => Http::response('')]);

    TeleportService::forCharacter($character)->setHomeTavern(376);

    expect($character->fresh()->home_tavern_room_id)->toBe(376);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'teleportupdate=1')
        && str_contains($request->url(), 'tavern=376'));
});

it('lists usable anchors and discovery targets separately', function () {
    $character = teleportCharacter();
    $known = TeleportAnchor::factory()->create(['name' => 'Known']);
    $unknown = TeleportAnchor::factory()->undiscovered()->create(['name' => 'Unknown']);
    $lost = TeleportAnchor::factory()->create(['name' => 'Lost']);

    foreach ([[$known, true], [$unknown, true], [$lost, false]] as [$anchor, $available]) {
        CharacterTeleportAnchor::create([
            'character_id' => $character->id,
            'teleport_anchor_id' => $anchor->id,
            'iid' => 1,
            'is_available' => $available,
        ]);
    }

    $service = TeleportService::forCharacter($character);

    expect(array_map(fn ($a) => $a->name, $service->usableAnchors()))->toBe(['Known'])
        ->and(array_map(fn ($a) => $a->name, $service->discoveryTargets()))->toBe(['Unknown']);
});
