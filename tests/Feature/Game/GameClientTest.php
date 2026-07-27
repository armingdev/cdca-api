<?php

use App\Game\Exceptions\SessionCollisionException;
use App\Game\Http\GameClient;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('targets the character server and does not follow redirects', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        'sigil.outwar.com/*' => Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/123/']),
    ]);

    $response = GameClient::forCharacter($character)->get('somethingelse.php', ['attackid' => 'ENC']);

    expect($response->status())->toBe(302)
        ->and($response->header('Location'))->toBe('https://sigil.outwar.com/attack/123/');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://sigil.outwar.com/somethingelse.php'));
});

it('keeps the jittered gap between consecutive requests under the throttle lock', function () {
    config(['outwar.http.throttle_min_ms' => 500, 'outwar.http.throttle_max_ms' => 500]);
    Sleep::fake();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    Http::fake(['sigil.outwar.com/*' => Http::response('ok')]);

    $client = GameClient::forCharacter($character);
    $client->get('userstats.php');
    $client->get('userstats.php');

    // First request finds no previous timestamp; the second sleeps out the gap.
    Sleep::assertSleptTimes(1);
});

it('detects a session collision and invalidates the rga', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'www.outwar.com/*' => Http::response('<html><title>Rampid Gaming Login</title></html>'),
    ]);

    expect(fn () => GameClient::forRga($rga)->get('some_page.php'))
        ->toThrow(SessionCollisionException::class)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_INVALID);
});

it('detects the ajax logged-out error box and invalidates the rga', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'www.outwar.com/*' => Http::response('<font>You must be logged in to view this page.</font>'),
    ]);

    expect(fn () => GameClient::forRga($rga)->get('ajax/trusteeList.php', ['dropdown' => 1]))
        ->toThrow(SessionCollisionException::class)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_INVALID);
});
