<?php

use App\Models\Character;
use App\Models\Rga;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('creates an RGA with encrypted credentials and never exposes them', function () {
    $response = $this->postJson('/api/v1/rgas', ['username' => 'linuxx', 'password' => 'hunter2']);

    $response->assertCreated()
        ->assertJsonPath('data.username', 'linuxx')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.cookies');

    $raw = DB::table('rgas')->where('username', 'linuxx')->first();
    expect($raw->password)->not->toContain('hunter2');
});

it('lists only the user\'s own RGAs with character counts', function () {
    $mine = Rga::factory()->for($this->user)->create();
    Character::factory()->for($mine)->count(2)->create();
    Rga::factory()->for(User::factory())->create(); // someone else's

    $this->getJson('/api/v1/rgas')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.characters_count', 2);
});

it('forbids viewing another user\'s RGA', function () {
    $other = Rga::factory()->for(User::factory())->create();

    $this->getJson("/api/v1/rgas/{$other->id}")->assertForbidden();
});

it('logs an RGA in and captures its session', function () {
    $rga = Rga::factory()->for($this->user)->create();

    Http::fake(['www.outwar.com/index.php' => Http::response('', 302, [
        'Set-Cookie' => ['rg_sess_id=abc; domain=.outwar.com', 'token=def; domain=.outwar.com', 'cuserid2=1; domain=.outwar.com'],
    ])]);

    $this->postJson("/api/v1/rgas/{$rga->id}/login")
        ->assertOk()
        ->assertJsonPath('data.has_session', true);
});

it('attaches a browser session after live verification', function () {
    $rga = Rga::factory()->for($this->user)->create();
    $sessionId = str_repeat('ab', 16);

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response(
        file_get_contents(base_path('tests/Fixtures/game/trustee_list.json')),
    )]);

    $this->postJson("/api/v1/rgas/{$rga->id}/session", ['rg_sess_id' => $sessionId])
        ->assertOk()
        ->assertJsonPath('data.has_session', true)
        ->assertJsonMissingPath('data.cookies');

    expect($rga->fresh()->cookies)->toBe(['rg_sess_id' => $sessionId]);
});

it('rejects a dead pasted session and leaves the stored session untouched', function () {
    $rga = Rga::factory()->for($this->user)->withSession()->create();
    $storedCookies = $rga->cookies;

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response(
        '<div class="error">You must be logged in to view this page.</div>',
    )]);

    $this->postJson("/api/v1/rgas/{$rga->id}/session", ['rg_sess_id' => str_repeat('ab', 16)])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'invalid or expired'));

    expect($rga->fresh()->cookies)->toBe($storedCookies)
        ->and($rga->fresh()->status)->toBe(Rga::STATUS_ACTIVE);
});

it('validates the rg_sess_id format', function (array $payload) {
    $rga = Rga::factory()->for($this->user)->create();

    Http::fake();

    $this->postJson("/api/v1/rgas/{$rga->id}/session", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rg_sess_id');

    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    'too short' => [['rg_sess_id' => str_repeat('a', 31)]],
    'non-hex' => [['rg_sess_id' => str_repeat('g', 32)]],
]);

it('forbids attaching a session to another user\'s RGA', function () {
    $other = Rga::factory()->for(User::factory())->create();

    $this->postJson("/api/v1/rgas/{$other->id}/session", ['rg_sess_id' => str_repeat('ab', 16)])
        ->assertForbidden();
});

it('reveals the stored session cookies', function () {
    $rga = Rga::factory()->for($this->user)->withSession()->create();

    $this->getJson("/api/v1/rgas/{$rga->id}/session")
        ->assertOk()
        ->assertJsonPath('data.rg_sess_id', $rga->cookies['rg_sess_id'])
        ->assertJsonPath('data.token', $rga->cookies['token']);
});

it('forbids revealing another user\'s RGA session', function () {
    $other = Rga::factory()->for(User::factory())->withSession()->create();

    $this->getJson("/api/v1/rgas/{$other->id}/session")->assertForbidden();
});

it('returns 404 when revealing an RGA with no captured session', function () {
    $rga = Rga::factory()->for($this->user)->create();

    $this->getJson("/api/v1/rgas/{$rga->id}/session")->assertNotFound();
});
