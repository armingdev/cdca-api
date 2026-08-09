<?php

use App\Game\Exceptions\GameException;
use App\Game\Items\BackpackService;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('loads and parses a backpack tab', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*backpackcontents.php*' => Http::response(gameFixture('backpack_contents.html'))]);

    $contents = BackpackService::forCharacter($character)->contents('regular');

    expect($contents->items)->toHaveCount(18)
        ->and($contents->itemsNamed('Collector Augment'))->toHaveCount(3);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'tab=regular'));
});

it('loads and parses the worn equipment', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*equipment.php*' => Http::response(gameFixture('equipment_page.html'))]);

    $equipment = BackpackService::forCharacter($character)->equipped();

    expect($equipment->all())->toHaveCount(20)
        ->and($equipment->itemsInSlotNamed('Weapon')[0]->name)->toBe('Executioner of the Stormforged Conqueror');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'equipment.php'));
});

it('equips items without a security answer', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*backpack_action.php*' => Http::response('{"status":"ok"}')]);

    BackpackService::forCharacter($character)->equip([1622676234]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        && $request['action'] === 'equip'
        && $request['itemids'] === ['1622676234']
        && ! isset($request['answer']));
});

it('deletes items with the stored security answer and qty', function () {
    $rga = Rga::factory()->withSession()->create(['security_answer' => 'test-answer']);
    $character = Character::factory()->for($rga)->create();

    Http::fake(['*backpack_action.php*' => Http::response('{"status":"ok"}')]);

    BackpackService::forCharacter($character)->delete([1626153110]);

    Http::assertSent(fn ($request) => $request['action'] === 'delete'
        && $request['itemids'] === ['1626153110']
        && $request['answer'] === 'test-answer'
        && $request['qty'] === '1');
});

it('refuses to delete when the rga has no security answer', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake();

    expect(fn () => BackpackService::forCharacter($character)->delete([1626153110]))
        ->toThrow(GameException::class, 'security answer');

    Http::assertNothingSent();
});

it('throws when the action is not confirmed', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*backpack_action.php*' => Http::response('{"status":"error"}')]);

    expect(fn () => BackpackService::forCharacter($character)->equip([123]))
        ->toThrow(GameException::class);
});

it('fetches and parses an item detail', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*item_rollover.php*' => Http::response(gameFixture('item_rollover.html'))]);

    $detail = BackpackService::forCharacter($character)->itemDetail(7680408);

    expect($detail->name)->toBe('Bone-Forge')
        ->and($detail->stat('arcane'))->toBe(75);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'id=7680408'));
});
