<?php

use App\Models\Character;
use App\Models\Rga;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('lists every live session the user holds in one response', function () {
    $bravo = Rga::factory()->for($this->user)->withSession()->create(['username' => 'bravo']);
    $alpha = Rga::factory()->for($this->user)->withSession()->create(['username' => 'alpha']);
    Character::factory()->for($alpha)->count(2)->create();
    Rga::factory()->for($this->user)->create(['username' => 'no-session']);
    Rga::factory()->for($this->user)->withSession()->invalid()->create(['username' => 'booted']);
    Rga::factory()->for(User::factory())->withSession()->create(['username' => 'someone-else']);

    Http::fake();

    $this->getJson('/api/v1/rgas/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.username', 'alpha')
        ->assertJsonPath('data.0.rg_sess_id', $alpha->cookies['rg_sess_id'])
        ->assertJsonPath('data.0.token', $alpha->cookies['token'])
        ->assertJsonPath('data.0.characters_count', 2)
        ->assertJsonPath('data.0.alive', null)
        ->assertJsonPath('data.1.username', 'bravo')
        ->assertJsonPath('data.1.rga_id', $bravo->id)
        ->assertJsonMissingPath('data.0.password');

    // Without verify the game is never asked.
    Http::assertNothingSent();
});

it('verifies each session on request and marks dead ones invalid', function () {
    $alive = Rga::factory()->for($this->user)->withSession()->create(['username' => 'alpha']);
    $dead = Rga::factory()->for($this->user)->withSession()->create(['username' => 'bravo']);

    // Probed in username order: alpha answers, bravo has been booted.
    Http::fake(['sigil.outwar.com/ajax/trusteeList.php*' => Http::sequence()
        ->push(trusteeListJson())
        ->push('<div class="error">You must be logged in to view this page.</div>')]);

    $this->getJson('/api/v1/rgas/sessions?verify=1')
        ->assertOk()
        ->assertJsonPath('data.0.alive', true)
        ->assertJsonPath('data.0.status', Rga::STATUS_ACTIVE)
        ->assertJsonPath('data.1.alive', false)
        ->assertJsonPath('data.1.status', Rga::STATUS_INVALID);

    expect($alive->fresh()->status)->toBe(Rga::STATUS_ACTIVE)
        ->and($dead->fresh()->status)->toBe(Rga::STATUS_INVALID);
});

it('leaves a session alone when the game cannot be reached', function () {
    $rga = Rga::factory()->for($this->user)->withSession()->create();

    Http::fake(['sigil.outwar.com/*' => Http::failedConnection()]);

    $this->getJson('/api/v1/rgas/sessions?verify=1')
        ->assertOk()
        ->assertJsonPath('data.0.alive', null);

    expect($rga->fresh()->status)->toBe(Rga::STATUS_ACTIVE);
});

it('requires authentication', function () {
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/rgas/sessions')->assertUnauthorized();
});
