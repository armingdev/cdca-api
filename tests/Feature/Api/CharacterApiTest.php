<?php

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Rga;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\SkillSeeder;
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

it('reports the whole selected set per skill when casting it now', function () {
    $character = Character::factory()->for($this->rga)->create();
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);
    Skill::create(['id' => 7, 'name' => 'On Guard', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);

    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => 4, 'cast_on_start' => true, 'trained_level' => 1]);
    // Selected but never trained: the reason it did not go off is the answer
    // the player needs, and a bare count could not carry it.
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => 7, 'cast_on_start' => true, 'trained_level' => 0, 'synced_at' => now()]);

    fakeSkillWorld();

    $this->postJson("/api/v1/characters/{$character->id}/cast", ['on_start' => true])
        ->assertOk()
        ->assertJsonPath('message', 'Cast 1 skill(s).')
        ->assertJsonPath('cast.0.name', 'Stealth')
        ->assertJsonPath('skipped.0.name', 'On Guard')
        ->assertJsonPath('skipped.0.reason', 'untrained')
        ->assertJsonPath('failed', []);
});

it('syncs a character\'s skills from the game', function () {
    $character = Character::factory()->for($this->rga)->create();
    (new SkillSeeder)->run();

    Http::fake([
        '*skills_info.php*' => Http::response(gameFixture('skills/skills_info_misc_triworld.html')),
        '*cast_skills.php?C=7*' => Http::response(gameFixture('skills/cast_skills_misc_tab.html')),
        '*cast_skills.php*' => Http::response(gameFixture('skills/cast_skills_page.html')),
    ]);

    $this->postJson("/api/v1/characters/{$character->id}/skills/sync")
        ->assertOk()
        ->assertJsonPath('skill_points', 15)
        ->assertJsonPath('active_buffs', 1);

    expect($character->fresh()->skill_points)->toBe(15);

    $skills = $this->getJson("/api/v1/characters/{$character->id}/skills")->assertOk()->json('data');
    $empower = collect($skills)->firstWhere('skill_id', 3);

    expect($empower['trained_level'])->toBe(1)
        ->and($empower['bonus_level'])->toBe(8)
        ->and($empower['effective_level'])->toBe(9)
        ->and($empower['castable'])->toBeTrue();
});

it('forbids syncing another user\'s character skills', function () {
    $other = Character::factory()->for(Rga::factory()->for(User::factory()))->create();

    $this->postJson("/api/v1/characters/{$other->id}/skills/sync")->assertForbidden();
});

it('trains a skill for a character', function () {
    $character = Character::factory()->for($this->rga)->create(['skill_points' => 5]);
    (new SkillSeeder)->run();

    Http::fake([
        '*skills_info.php*' => Http::response(gameFixture('skills/skills_info_trained_recharging.html')),
        '*cast_skills.php*' => Http::response(gameFixture('skills/cast_skills_page.html')),
    ]);

    // The fixture's skill log confirms "Trained Teleport Level 1".
    $this->postJson("/api/v1/characters/{$character->id}/skills/27/train")
        ->assertOk()
        ->assertJsonPath('new_level', 1);
});

it('rejects training a misc skill with a validation error', function () {
    $character = Character::factory()->for($this->rga)->create(['skill_points' => 5]);
    Skill::create(['id' => 46, 'name' => 'Shield Wall', 'school' => 'misc', 'rage_cost' => 200]);

    $this->postJson("/api/v1/characters/{$character->id}/skills/46/train")
        ->assertStatus(422);

    Http::fake();
    Http::assertNothingSent();
});

it('lists the skill catalog filtered by school', function () {
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);
    Skill::create(['id' => 9, 'name' => 'Boost', 'school' => 'ferocity', 'rage_cost' => 10, 'cooldown_minutes' => 120, 'duration_minutes' => 60]);

    $this->getJson('/api/v1/skills?school=ferocity')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Boost');
});

it('skips the game read when the character\'s skills were synced recently', function () {
    $character = Character::factory()->for($this->rga)->create(['skill_points' => 15]);
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);
    CharacterSkill::create([
        'character_id' => $character->id,
        'skill_id' => 4,
        'trained_level' => 2,
        'cast_on_start' => true,
        'synced_at' => now()->subMinute(),
    ]);

    $this->postJson("/api/v1/characters/{$character->id}/skills/sync", ['max_age_seconds' => 300])
        ->assertOk()
        ->assertJsonPath('synced', false)
        ->assertJsonPath('rows_synced', 0)
        // Everything but the counts still describes the character as it stands.
        ->assertJsonPath('skill_points', 15)
        // A plain array, like the sibling teleport sync — no data envelope.
        ->assertJsonCount(1, 'skills')
        ->assertJsonPath('skills.0.skill_id', 4);

    Http::fake();
    Http::assertNothingSent();
});

it('syncs when the stored skill state is older than the requested max age', function () {
    $character = Character::factory()->for($this->rga)->create();
    (new SkillSeeder)->run();
    CharacterSkill::create([
        'character_id' => $character->id,
        'skill_id' => 3,
        'trained_level' => 1,
        'synced_at' => now()->subHours(2),
    ]);

    Http::fake([
        '*skills_info.php*' => Http::response(gameFixture('skills/skills_info_misc_triworld.html')),
        '*cast_skills.php?C=7*' => Http::response(gameFixture('skills/cast_skills_misc_tab.html')),
        '*cast_skills.php*' => Http::response(gameFixture('skills/cast_skills_page.html')),
    ]);

    $this->postJson("/api/v1/characters/{$character->id}/skills/sync", ['max_age_seconds' => 300])
        ->assertOk()
        ->assertJsonPath('synced', true)
        ->assertJsonPath('skill_points', 15);
});

it('reads a recharge window for every trained skill only when asked', function () {
    $character = Character::factory()->for($this->rga)->create();
    (new SkillSeeder)->run();

    Http::fake([
        '*skills_info.php*' => Http::response(gameFixture('skills/skills_info_trained_recharging.html')),
        '*cast_skills.php?C=7*' => Http::response(gameFixture('skills/cast_skills_misc_tab.html')),
        '*cast_skills.php*' => Http::response(gameFixture('skills/cast_skills_page.html')),
    ]);

    $this->postJson("/api/v1/characters/{$character->id}/skills/sync")->assertOk();

    $trainedIds = CharacterSkill::where('character_id', $character->id)
        ->where('trained_level', '>=', 1)
        ->pluck('skill_id');

    expect($trainedIds)->not->toBeEmpty()
        ->and(CharacterSkill::where('character_id', $character->id)->whereNotNull('recharge_synced_at')->exists())
        ->toBeFalse();

    $this->postJson("/api/v1/characters/{$character->id}/skills/sync", ['with_recharge' => true])
        ->assertOk()
        ->assertJsonPath('synced', true);

    $stamped = CharacterSkill::where('character_id', $character->id)
        ->whereNotNull('recharge_synced_at')
        ->pluck('skill_id');

    expect($stamped->sort()->values()->all())->toBe($trainedIds->sort()->values()->all());
});

it('reports the resolved buff and cooldown windows on each skill row', function () {
    $character = Character::factory()->for($this->rga)->create();
    Skill::create(['id' => 9, 'name' => 'Boost', 'school' => 'ferocity', 'rage_cost' => 10, 'cooldown_minutes' => 120, 'duration_minutes' => 60]);
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);

    // Cast 30 minutes ago: 60m of buff still running, 120m of cooldown still to go.
    CharacterSkill::create([
        'character_id' => $character->id,
        'skill_id' => 9,
        'trained_level' => 1,
        'cast_on_start' => true,
        'last_cast_at' => now()->subMinutes(30),
        'synced_at' => now(),
    ]);
    // Trained but never cast: nothing to count down, ready to go.
    CharacterSkill::create([
        'character_id' => $character->id,
        'skill_id' => 4,
        'trained_level' => 1,
        'cast_on_start' => true,
        'synced_at' => now(),
    ]);

    $rows = collect($this->getJson("/api/v1/characters/{$character->id}/skills")->assertOk()->json('data'));

    $running = $rows->firstWhere('skill_id', 9);
    expect($running['buff_active'])->toBeTrue()
        ->and($running['on_cooldown'])->toBeTrue()
        ->and($running['ready'])->toBeFalse()
        ->and($running['buff_ends_at'])->not->toBeNull()
        ->and($running['cooldown_ends_at'])->not->toBeNull();

    $idle = $rows->firstWhere('skill_id', 4);
    expect($idle['buff_active'])->toBeFalse()
        ->and($idle['on_cooldown'])->toBeFalse()
        ->and($idle['ready'])->toBeTrue()
        ->and($idle['buff_ends_at'])->toBeNull()
        ->and($idle['cooldown_ends_at'])->toBeNull();
});
