<?php

use App\Game\Skills\SkillCaster;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Rga;
use App\Models\Skill;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function makeSkill(int $id, string $name, int $cooldown, int $duration, int $rage = 10): Skill
{
    return Skill::create([
        'id' => $id, 'name' => $name, 'school' => 'class',
        'rage_cost' => $rage, 'cooldown_minutes' => $cooldown, 'duration_minutes' => $duration,
    ]);
}

it('casts a skill, confirms it, and records last_cast_at', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $skill = makeSkill(4, 'Stealth', 60, 60);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    expect(SkillCaster::forCharacter($character)->cast($skill))->toBeTrue();

    $state = CharacterSkill::where('character_id', $character->id)->where('skill_id', 4)->first();

    expect($state->last_cast_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'cast_skills.php')
        && $request['castskillid'] == 4
        && $request['cast'] === 'Cast Skill');
});

it('does not record a cast the game rejected', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $skill = makeSkill(4, 'Stealth', 60, 60);

    Http::fake(['*cast_skills.php*' => Http::response('Your rage is too low.')]);

    expect(SkillCaster::forCharacter($character)->cast($skill))->toBeFalse()
        ->and(CharacterSkill::count())->toBe(0);
});

it('casts only the selected skills that are neither active nor on cooldown', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $stealth = makeSkill(4, 'Stealth', 60, 60);      // castable
    $boost = makeSkill(9, 'Boost', 120, 60);         // active buff → skip
    $onGuard = makeSkill(7, 'On Guard', 300, 60);    // buff expired but on cooldown → skip

    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $stealth->id, 'cast_on_start' => true]);
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $boost->id, 'cast_on_start' => true, 'last_cast_at' => now()]);
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $onGuard->id, 'cast_on_start' => true, 'last_cast_at' => now()->subMinutes(70)]);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    $cast = SkillCaster::forCharacter($character)->castOnStart();

    expect($cast)->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['castskillid'] == 4);
});

it('prefers the server-read recharge window over the computed cooldown', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $stealth = makeSkill(4, 'Stealth', 60, 60);

    // Computed cooldown expired long ago, but the server says still recharging.
    CharacterSkill::create([
        'character_id' => $character->id, 'skill_id' => $stealth->id, 'cast_on_start' => true,
        'last_cast_at' => now()->subMinutes(500), 'recharge_until' => now()->addMinutes(30),
        'synced_at' => now(),
    ]);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    expect(SkillCaster::forCharacter($character)->castOnStart())->toBe(0);
    Http::assertNothingSent();
});

it('clears server-read windows when a cast succeeds', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $stealth = makeSkill(4, 'Stealth', 60, 60);

    CharacterSkill::create([
        'character_id' => $character->id, 'skill_id' => $stealth->id,
        'recharge_until' => now()->subMinutes(5), 'buff_until' => now()->subMinutes(5),
        'synced_at' => now(),
    ]);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    expect(SkillCaster::forCharacter($character)->cast($stealth))->toBeTrue();

    $state = CharacterSkill::where('character_id', $character->id)->where('skill_id', 4)->first();

    expect($state->recharge_until)->toBeNull()
        ->and($state->buff_until)->toBeNull()
        ->and($state->last_cast_at)->not->toBeNull();
});

it('skips synced untrained skills on cast-on-start', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $stealth = makeSkill(4, 'Stealth', 60, 60);

    // Synced and known-untrained → uncastable, skip without a request.
    CharacterSkill::create([
        'character_id' => $character->id, 'skill_id' => $stealth->id, 'cast_on_start' => true,
        'trained_level' => 0, 'bonus_level' => 8, 'synced_at' => now(),
    ]);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    expect(SkillCaster::forCharacter($character)->castOnStart())->toBe(0);
    Http::assertNothingSent();
});

it('reflects the Circumspect buff window', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    makeSkill(Skill::CIRCUMSPECT_ID, 'Circumspect', 720, 60, 20);

    $caster = SkillCaster::forCharacter($character);

    expect($caster->isCircumspectActive())->toBeFalse();

    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => Skill::CIRCUMSPECT_ID, 'last_cast_at' => now()]);
    expect(SkillCaster::forCharacter($character->fresh())->isCircumspectActive())->toBeTrue();

    CharacterSkill::where('character_id', $character->id)->update(['last_cast_at' => now()->subMinutes(70)]);
    expect(SkillCaster::forCharacter($character->fresh())->isCircumspectActive())->toBeFalse();
});

it('ensures Circumspect by casting when off cooldown', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    makeSkill(Skill::CIRCUMSPECT_ID, 'Circumspect', 720, 60, 20);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Circumspect')]);

    expect(SkillCaster::forCharacter($character)->ensureCircumspect())->toBeTrue();

    Http::assertSent(fn ($request) => $request['castskillid'] == Skill::CIRCUMSPECT_ID);
});

it('cannot ensure Circumspect while it is on cooldown and inactive', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    makeSkill(Skill::CIRCUMSPECT_ID, 'Circumspect', 720, 60, 20);

    // Cast 70m ago: buff (60m) expired, cooldown (720m) still active.
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => Skill::CIRCUMSPECT_ID, 'last_cast_at' => now()->subMinutes(70)]);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Circumspect')]);

    expect(SkillCaster::forCharacter($character)->ensureCircumspect())->toBeFalse();

    Http::assertNothingSent();
});
