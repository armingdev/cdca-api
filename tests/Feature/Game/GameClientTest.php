<?php

use App\Game\Exceptions\GameServerException;
use App\Game\Exceptions\SessionCollisionException;
use App\Game\Http\GameClient;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Http\Client\RequestException;
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
        'outwar.com/*' => Http::response('<html><title>Rampid Gaming Login</title></html>'),
    ]);

    expect(fn () => GameClient::forRga($rga)->get('some_page.php'))
        ->toThrow(SessionCollisionException::class)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_INVALID);
});

/**
 * Every body below is a real response captured 2026-08-16 from a session the
 * game had genuinely booted. The wording differs per endpoint, which is why
 * the sentinel is the bare "You must be logged in" prefix.
 */
it('detects each logged-out response variant and invalidates the rga', function (string $body) {
    $rga = Rga::factory()->withSession()->create();

    Http::fake(['outwar.com/*' => Http::response($body)]);

    expect(fn () => GameClient::forRga($rga)->get('some_page.php'))
        ->toThrow(SessionCollisionException::class)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_INVALID);
})->with([
    'trusteeList error box' => '<font>You must be logged in to view this page.</font>',
    'userstats.php' => 'You must be logged in to use this page',
    'world_questHelper.php' => '{"error":"You must be logged in to do that"}',
    'accounts.php' => 'No account id',
]);

it('detects a full page bouncing to the login screen', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'outwar.com/*' => Http::response('', 302, ['Location' => 'https://outwar.com/login']),
    ]);

    expect(fn () => GameClient::forRga($rga)->get('world', ['room' => 1]))
        ->toThrow(SessionCollisionException::class)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_INVALID);
});

/**
 * A dead session also makes ajax endpoints answer 500 with an empty body, but
 * a server blip looks identical — so a 5xx surfaces as a game server error
 * and must NOT invalidate the RGA, or a blip would trigger a re-login storm.
 */
it('leaves the rga alone on a 5xx, which a server blip and a dead session share', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake(['outwar.com/*' => Http::response('', 500)]);

    expect(fn () => GameClient::forRga($rga)->get('ajax/trusteeList.php', ['dropdown' => 1]))
        ->toThrow(GameServerException::class)
        ->and($rga->fresh()->status)->not->toBe(Rga::STATUS_INVALID);
});

it('raises a readable game server error for a 5xx, keeping the game alert apart from an outage', function (string $body, int $status, string $message, ?string $alert) {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['sigil.outwar.com/*' => Http::response($body, $status)]);

    try {
        GameClient::forCharacter($character)->get('somethingelse.php', ['attackid' => 'ENC']);
    } catch (GameServerException $exception) {
        expect($exception->getMessage())->toBe($message)
            ->and($exception->gameAlert())->toBe($alert);

        return;
    }

    $this->fail('Expected GameServerException was not thrown.');
})->with([
    'the game refusing a vanished mob' => [
        gameFixture('attack_invalid_mob_500.html'), 500,
        'Game server answered HTTP 500 for somethingelse.php: Invalid mob!', 'Invalid mob!',
    ],
    'cloudflare with the origin down' => [
        'error code: 521', 521,
        'Game server answered HTTP 521 for somethingelse.php: error code: 521', null,
    ],
    'an html bad gateway page' => [
        '<html><head><style>body{color:red}</style><title>502 Bad Gateway</title></head><body><h1>502 Bad Gateway</h1></body></html>', 502,
        'Game server answered HTTP 502 for somethingelse.php: 502 Bad Gateway 502 Bad Gateway', null,
    ],
]);

it('sends a request that hit a 5xx only once, so an attack is never repeated', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['sigil.outwar.com/*' => Http::response('error code: 502', 502)]);

    expect(fn () => GameClient::forCharacter($character)->get('somethingelse.php', ['attackid' => 'ENC']))
        ->toThrow(GameServerException::class);

    Http::assertSentCount(1);
});

it('still raises a request exception for a 4xx', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['sigil.outwar.com/*' => Http::response('Not Found', 404)]);

    expect(fn () => GameClient::forCharacter($character)->get('no_such_page.php'))
        ->toThrow(RequestException::class);
});

it('does not mistake a normal attack redirect for a login bounce', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'outwar.com/*' => Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/123/']),
    ]);

    expect(GameClient::forRga($rga)->get('somethingelse.php')->status())->toBe(302)
        ->and($rga->fresh()->status)->not->toBe(Rga::STATUS_INVALID);
});
