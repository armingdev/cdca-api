<?php

use App\Game\Exceptions\SessionCollisionException;
use App\Game\Http\GameClient;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

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

it('detects a session collision and invalidates the rga', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'www.outwar.com/*' => Http::response('<html><title>Rampid Gaming Login</title></html>'),
    ]);

    expect(fn () => GameClient::forRga($rga)->get('some_page.php'))
        ->toThrow(SessionCollisionException::class)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_INVALID);
});
