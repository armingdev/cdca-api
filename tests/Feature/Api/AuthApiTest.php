<?php

use App\Jobs\SyncRgaCharactersJob;
use App\Models\Rga;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('registers a user and returns a token', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Armin',
        'email' => 'armin@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token'])
        ->assertJsonMissingPath('user.password');

    expect(User::where('email', 'armin@example.com')->exists())->toBeTrue();
});

it('rejects registration with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/register', [
        'name' => 'X',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(422)->assertJsonValidationErrorFor('email');
});

it('logs in with valid credentials and issues a token', function () {
    User::factory()->create(['email' => 'armin@example.com', 'password' => 'secret-pass']);

    $this->postJson('/api/v1/login', ['email' => 'armin@example.com', 'password' => 'secret-pass'])
        ->assertOk()
        ->assertJsonStructure(['user', 'token']);
});

it('rejects login with wrong credentials', function () {
    User::factory()->create(['email' => 'armin@example.com', 'password' => 'secret-pass']);

    $this->postJson('/api/v1/login', ['email' => 'armin@example.com', 'password' => 'wrong'])
        ->assertStatus(422)->assertJsonValidationErrorFor('email');
});

it('returns the authenticated user and logs out', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('user.id', $user->id);
    $this->postJson('/api/v1/logout')->assertOk();
});

it('blocks unauthenticated access to protected routes', function () {
    $this->getJson('/api/v1/rgas')->assertUnauthorized();
    $this->getJson('/api/v1/runs')->assertUnauthorized();
});

it('queues a character-list sync for each connected account on login', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'armin@example.com', 'password' => Hash::make('secret-pass')]);
    Rga::factory()->for($user)->withSession()->count(2)->create();
    // Someone else's account must not be swept along.
    Rga::factory()->for(User::factory())->withSession()->create();

    $this->postJson('/api/v1/login', ['email' => 'armin@example.com', 'password' => 'secret-pass'])
        ->assertOk();

    Queue::assertPushed(SyncRgaCharactersJob::class, 2);
});

it('queues nothing on login for a user with no accounts', function () {
    Queue::fake();

    User::factory()->create(['email' => 'solo@example.com', 'password' => Hash::make('secret-pass')]);

    $this->postJson('/api/v1/login', ['email' => 'solo@example.com', 'password' => 'secret-pass'])
        ->assertOk();

    Queue::assertNotPushed(SyncRgaCharactersJob::class);
});
