<?php

use App\Game\Auth\LoginService;
use App\Game\Exceptions\LoginFailedException;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

it('captures the rga session cookies from the login 302', function () {
    $rga = Rga::factory()->create(['username' => 'linuxx', 'password' => 'hunter2']);

    Http::fake([
        'www.outwar.com/index.php' => Http::response('', 302, [
            'Location' => 'https://sigil.outwar.com/world?suid=2403&serverid=1&code=1',
            'Set-Cookie' => [
                'rg_sess_id=d97cabc123; path=/; domain=.outwar.com',
                'token=7b50def456; path=/; domain=.outwar.com; Max-Age=604800',
                'cuserid2=3920; path=/; domain=.outwar.com; Max-Age=604800',
                'owip=203.0.113.7; path=/; domain=.outwar.com; Max-Age=604800',
                'ow_userid=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT',
            ],
        ]),
    ]);

    $rga = app(LoginService::class)->login($rga);

    expect($rga->cookies)->toBe([
        'rg_sess_id' => 'd97cabc123',
        'token' => '7b50def456',
        'cuserid2' => '3920',
        'owip' => '203.0.113.7',
    ])
        ->and($rga->status)->toBe(Rga::STATUS_ACTIVE)
        ->and($rga->last_login_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://www.outwar.com/index.php'
        && $request['login_username'] === 'linuxx'
        && $request['login_password'] === 'hunter2'
        && $request['serverid'] === 1);
});

it('fails when the login does not redirect', function () {
    $rga = Rga::factory()->create();

    Http::fake(['www.outwar.com/index.php' => Http::response('Invalid password', 200)]);

    app(LoginService::class)->login($rga);
})->throws(LoginFailedException::class, 'did not redirect');

it('fails when the redirect sets no rg_sess_id', function () {
    $rga = Rga::factory()->create();

    Http::fake([
        'www.outwar.com/index.php' => Http::response('', 302, [
            'Location' => 'https://www.outwar.com/login',
        ]),
    ]);

    app(LoginService::class)->login($rga);
})->throws(LoginFailedException::class, 'rg_sess_id');

it('attaches a verified pasted session with all provided cookies', function () {
    $rga = Rga::factory()->create();

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response(
        file_get_contents(base_path('tests/Fixtures/game/trustee_list.json')),
    )]);

    $rga = app(LoginService::class)->attachSession($rga, 'd97c'.str_repeat('a', 28), 'tok123', '3920');

    expect($rga->cookies)->toBe([
        'rg_sess_id' => 'd97c'.str_repeat('a', 28),
        'token' => 'tok123',
        'cuserid2' => '3920',
    ])
        ->and($rga->status)->toBe(Rga::STATUS_ACTIVE)
        ->and($rga->last_login_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://sigil.outwar.com/ajax/trusteeList.php?dropdown=1');
});

it('attaches only the rg_sess_id when the optional cookies are omitted', function () {
    $rga = Rga::factory()->create();

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response(
        file_get_contents(base_path('tests/Fixtures/game/trustee_list.json')),
    )]);

    $rga = app(LoginService::class)->attachSession($rga, str_repeat('ab', 16));

    expect($rga->cookies)->toBe(['rg_sess_id' => str_repeat('ab', 16)]);
});

it('rejects a dead pasted session without mutating the rga', function () {
    $rga = Rga::factory()->withSession()->create();
    $storedCookies = $rga->cookies;

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response(
        '<div class="error">You must be logged in to view this page.</div>',
    )]);

    try {
        app(LoginService::class)->attachSession($rga, str_repeat('ab', 16));
    } catch (LoginFailedException $exception) {
        expect($exception->getMessage())->toContain('invalid or expired')
            ->and($rga->fresh()->cookies)->toBe($storedCookies)
            ->and($rga->fresh()->status)->toBe(Rga::STATUS_ACTIVE);

        return;
    }

    $this->fail('Expected LoginFailedException was not thrown.');
});

it('rejects a pasted session when the probe body is not the trustee JSON', function () {
    $rga = Rga::factory()->create();

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response('<html>maintenance</html>')]);

    app(LoginService::class)->attachSession($rga, str_repeat('ab', 16));
})->throws(LoginFailedException::class, 'invalid or expired');

it('rejects a pasted session on a boot sentinel or bad status', function (string $body, int $status) {
    $rga = Rga::factory()->create();

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response($body, $status)]);

    app(LoginService::class)->attachSession($rga, str_repeat('ab', 16));
})->with([
    'boot sentinel' => ['<html>Rampid Gaming Login</html>', 200],
    'server error' => ['', 500],
])->throws(LoginFailedException::class, 'invalid or expired');
