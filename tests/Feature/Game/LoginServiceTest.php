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
