<?php

use App\Models\Character;
use App\Models\CharacterPotion;
use App\Models\Potion;
use App\Models\Rga;
use App\Models\User;
use Database\Seeders\PotionSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->character = Character::factory()->for(Rga::factory()->for($this->user)->withSession())->create();
});

it('lists the catalog, filtered by kind or name', function () {
    Potion::factory()->create(['name' => 'Kix Potion']);
    Potion::factory()->rage()->create(['name' => 'Rage Potion']);
    Potion::factory()->other()->create(['name' => 'Arcane Potion']);

    $this->getJson('/api/v1/potions')->assertOk()->assertJsonCount(3, 'data');

    $this->getJson('/api/v1/potions?kind=rage')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Rage Potion'])
        ->assertJsonPath('data.0.rage_amount', 1000);

    $this->getJson('/api/v1/potions?search=kix')->assertOk()->assertJsonPath('data.*.name', ['Kix Potion']);
    $this->getJson('/api/v1/potions?kind=elixir')->assertUnprocessable()->assertJsonValidationErrors('kind');
});

it('shows a character\'s stored potions without asking the game', function () {
    Http::fake();
    CharacterPotion::factory()->for($this->character)
        ->for(Potion::factory()->create(['name' => 'Kix Potion']))
        ->create(['quantity' => 4, 'active_until' => now()->addMinutes(30)]);

    $this->getJson("/api/v1/characters/{$this->character->id}/potions")
        ->assertOk()
        ->assertJsonPath('data.0.potion.name', 'Kix Potion')
        ->assertJsonPath('data.0.quantity', 4)
        ->assertJsonPath('data.0.active', true);

    Http::assertNothingSent();
});

it('syncs a character\'s potions from the game', function () {
    $this->seed(PotionSeeder::class);
    fakePotionEndpoints();
    Http::fake(['*cast_skills.php*' => Http::response(gameFixture('potions/cast_skills_potion_active.html'))]);

    $this->postJson("/api/v1/characters/{$this->character->id}/potions/sync")
        ->assertOk()
        ->assertJsonCount(43, 'data')
        ->assertJsonFragment(['quantity' => 96, 'active' => true]);
});

it('drinks a potion on demand and reports why it could not', function () {
    $this->seed(PotionSeeder::class);
    $kix = Potion::where('name', 'Kix Potion')->sole();
    fakePotionEndpoints();

    $this->postJson("/api/v1/characters/{$this->character->id}/potions/{$kix->id}/activate")
        ->assertOk()
        ->assertJsonPath('outcome', 'activated')
        ->assertJsonPath('data.quantity', 95);

    $this->postJson("/api/v1/characters/{$this->character->id}/potions/".Potion::where('name', 'Arcane Potion')->value('id').'/activate')
        ->assertUnprocessable()
        ->assertJsonPath('outcome', 'out_of_stock')
        ->assertJsonPath('message', 'No Arcane Potion left.');
});

it('needs a live session to talk to the game', function () {
    $this->character->rga->update(['cookies' => null]);
    $potion = Potion::factory()->create();
    Http::fake();

    $this->postJson("/api/v1/characters/{$this->character->id}/potions/sync")->assertUnprocessable();
    $this->postJson("/api/v1/characters/{$this->character->id}/potions/{$potion->id}/activate")->assertUnprocessable();

    Http::assertNothingSent();
});

it('keeps another user\'s character off limits', function () {
    $foreign = Character::factory()->for(Rga::factory()->for(User::factory())->withSession())->create();
    $potion = Potion::factory()->create();
    Http::fake();

    $this->getJson("/api/v1/characters/{$foreign->id}/potions")->assertForbidden();
    $this->postJson("/api/v1/characters/{$foreign->id}/potions/sync")->assertForbidden();
    $this->postJson("/api/v1/characters/{$foreign->id}/potions/{$potion->id}/activate")->assertForbidden();

    Http::assertNothingSent();
});
