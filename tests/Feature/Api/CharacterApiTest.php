<?php

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Rga;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
});

it('lists only the user\'s characters', function () {
    Character::factory()->for($this->rga)->count(2)->create();
    Character::factory()->for(Rga::factory()->for(User::factory()))->create();

    $this->getJson('/api/v1/characters')->assertOk()->assertJsonCount(2, 'data');
});

it('forbids viewing another user\'s character', function () {
    $other = Character::factory()->for(Rga::factory()->for(User::factory()))->create();

    $this->getJson("/api/v1/characters/{$other->id}")->assertForbidden();
});

it('updates and reads a character\'s cast-on-start skill selection', function () {
    $character = Character::factory()->for($this->rga)->create();
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);
    Skill::create(['id' => 3008, 'name' => 'Circumspect', 'school' => 'ferocity', 'rage_cost' => 20, 'cooldown_minutes' => 720, 'duration_minutes' => 60]);

    $this->putJson("/api/v1/characters/{$character->id}/skills", ['skill_ids' => [4, 3008]])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(CharacterSkill::where('character_id', $character->id)->where('cast_on_start', true)->count())->toBe(2);

    // Deselect one.
    $this->putJson("/api/v1/characters/{$character->id}/skills", ['skill_ids' => [4]])
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('casts a skill for a character', function () {
    $character = Character::factory()->for($this->rga)->create();
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    $this->postJson("/api/v1/characters/{$character->id}/cast", ['skill_id' => 4])
        ->assertOk()
        ->assertJsonPath('message', 'Cast Stealth.');
});

it('lists the skill catalog filtered by school', function () {
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);
    Skill::create(['id' => 9, 'name' => 'Boost', 'school' => 'ferocity', 'rage_cost' => 10, 'cooldown_minutes' => 120, 'duration_minutes' => 60]);

    $this->getJson('/api/v1/skills?school=ferocity')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Boost');
});
