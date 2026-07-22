<?php

use App\Models\User;
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
