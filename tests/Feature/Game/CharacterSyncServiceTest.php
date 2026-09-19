<?php

use App\Game\Auth\CharacterSyncService;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('discovers characters on both servers and upserts them', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml()),
        'torax.outwar.com/accounts.php*' => Http::response(gameFixture('accounts_enumeration.html')),
        '*ajax/trusteeList.php*' => Http::response(trusteeListJson()),
    ]);

    $characters = app(CharacterSyncService::class)->sync($rga);

    expect($characters)->toHaveCount(2)
        ->and(Character::count())->toBe(2);

    $sigil = Character::where('server_id', 1)->first();
    $torax = Character::where('server_id', 2)->first();

    expect($sigil->suid)->toBe(2403)
        ->and($sigil->name)->toBe('RealLinuXX')
        ->and($sigil->level)->toBe(85)
        ->and($sigil->rga_id)->toBe($rga->id)
        ->and($torax->suid)->toBe(21980)
        ->and($torax->name)->toBe('LinuXX')
        ->and($torax->crew)->toBe('LinuXXisl33t');
});

it('updates existing characters instead of duplicating them', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml(86)),
        'torax.outwar.com/accounts.php*' => Http::response('<html></html>'),
        '*ajax/trusteeList.php*' => Http::response(trusteeListJson()),
    ]);

    Character::factory()->for($rga)->create(['suid' => 2403, 'server_id' => 1, 'level' => 85]);

    app(CharacterSyncService::class)->sync($rga);

    expect(Character::count())->toBe(1)
        ->and(Character::first()->level)->toBe(86);
});

it('flags the characters the trustee list names and leaves the RGA\'s own unflagged', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml()),
        'torax.outwar.com/accounts.php*' => Http::response(gameFixture('accounts_enumeration.html')),
        'sigil.outwar.com/ajax/trusteeList.php*' => Http::response(trusteeListJson([2403])),
        'torax.outwar.com/ajax/trusteeList.php*' => Http::response(trusteeListJson()),
    ]);

    app(CharacterSyncService::class)->sync($rga);

    expect(Character::where('suid', 2403)->value('is_trustee'))->toBeTrue()
        ->and(Character::where('suid', 21980)->value('is_trustee'))->toBeFalse();
});

it('clears the flag once a character is no longer a trustee', function () {
    $rga = Rga::factory()->withSession()->create();
    Character::factory()->for($rga)->trustee()->create(['suid' => 2403, 'server_id' => 1]);

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml()),
        'torax.outwar.com/accounts.php*' => Http::response('<html></html>'),
        '*ajax/trusteeList.php*' => Http::response(trusteeListJson()),
    ]);

    app(CharacterSyncService::class)->sync($rga);

    expect(Character::where('suid', 2403)->value('is_trustee'))->toBeFalse();
});

it('still syncs the roster and keeps the known flags when the trustee list is unreadable', function () {
    $rga = Rga::factory()->withSession()->create();
    Character::factory()->for($rga)->trustee()->create(['suid' => 2403, 'server_id' => 1, 'level' => 80]);

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml(86)),
        'torax.outwar.com/accounts.php*' => Http::response('<html></html>'),
        '*ajax/trusteeList.php*' => Http::response('<html>maintenance</html>'),
    ]);

    app(CharacterSyncService::class)->sync($rga);

    $character = Character::where('suid', 2403)->first();

    expect($character->level)->toBe(86)
        ->and($character->is_trustee)->toBeTrue();
});

it('does not take a character away from its own connected RGA over a trustee grant', function () {
    $owner = Rga::factory()->withSession()->create();
    $trusteeHolder = Rga::factory()->withSession()->create();
    Character::factory()->for($owner)->create(['suid' => 2403, 'server_id' => 1]);

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml()),
        'torax.outwar.com/accounts.php*' => Http::response('<html></html>'),
        '*ajax/trusteeList.php*' => Http::response(trusteeListJson([2403])),
    ]);

    app(CharacterSyncService::class)->sync($trusteeHolder);

    $character = Character::where('suid', 2403)->first();

    expect($character->rga_id)->toBe($owner->id)
        ->and($character->is_trustee)->toBeFalse();
});
