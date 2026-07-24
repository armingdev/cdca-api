<?php

use App\Models\Rga;
use Illuminate\Support\Facades\Http;

it('attaches a pasted session via the command', function () {
    $rga = Rga::factory()->create();
    $sessionId = str_repeat('ab', 16);

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response(
        file_get_contents(base_path('tests/Fixtures/game/trustee_list.json')),
    )]);

    $this->artisan('outwar:rga-attach', ['rga' => $rga->id, '--session' => $sessionId])
        ->assertSuccessful()
        ->expectsOutputToContain('Session attached');

    expect($rga->fresh()->cookies)->toBe(['rg_sess_id' => $sessionId]);
});

it('fails the attach command when the session is dead', function () {
    $rga = Rga::factory()->create();

    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::response(
        '<div class="error">You must be logged in to view this page.</div>',
    )]);

    $this->artisan('outwar:rga-attach', ['rga' => $rga->id, '--session' => str_repeat('ab', 16)])
        ->assertFailed()
        ->expectsOutputToContain('invalid or expired');
});

it('rejects a malformed session id in the attach command', function () {
    $rga = Rga::factory()->create();

    Http::fake();

    $this->artisan('outwar:rga-attach', ['rga' => $rga->id, '--session' => 'not-hex'])
        ->assertFailed()
        ->expectsOutputToContain('32 hex characters');

    Http::assertNothingSent();
});

it('reveals the stored session cookies via the command', function () {
    $rga = Rga::factory()->withSession()->create();

    $this->artisan('outwar:rga-session', ['rga' => $rga->id])
        ->assertSuccessful()
        ->expectsOutputToContain($rga->cookies['rg_sess_id']);
});

it('fails the reveal command when no session was captured', function () {
    $rga = Rga::factory()->create();

    $this->artisan('outwar:rga-session', ['rga' => $rga->id])
        ->assertFailed()
        ->expectsOutputToContain('No session captured');
});
