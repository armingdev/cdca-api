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
