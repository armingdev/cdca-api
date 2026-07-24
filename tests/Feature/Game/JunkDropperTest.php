<?php

use App\Game\Items\JunkDropper;
use App\Models\Character;
use App\Models\JunkItem;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('drops items whose backpack name is in the junk list, without rollover calls', function () {
    JunkItem::factory()->create(['name' => 'Veldarian Augment']);

    $rga = Rga::factory()->withSession()->create(['security_answer' => 'test-answer']);
    $character = Character::factory()->for($rga)->create();

    Http::fake([
        '*backpackcontents.php*' => Http::response(gameFixture('backpack_contents.html')),
        '*backpack_action.php*' => Http::response('{"status":"ok"}'),
    ]);

    $summary = JunkDropper::forCharacter($character)->dropJunk();

    expect($summary->scanned)->toBe(18)
        ->and($summary->dropped)->toBe(1)
        ->and($summary->droppedNames)->toBe(['Veldarian Augment']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        && $request['action'] === 'delete'
        && $request['itemids'] === ['1738613300']
        && $request['answer'] === 'test-answer');

    // Names resolve from the list itself — no item_rollover traffic.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'item_rollover.php'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        && $request['itemids'] !== ['1738613300']);
});

it('skips the sweep entirely when the rga has no security answer', function () {
    JunkItem::factory()->create(['name' => 'Veldarian Augment']);
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake();

    $summary = JunkDropper::forCharacter($character)->dropJunk();

    expect($summary->scanned)->toBe(0)
        ->and($summary->dropped)->toBe(0);

    Http::assertNothingSent();
});
