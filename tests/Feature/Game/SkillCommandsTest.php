<?php

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Rga;
use App\Models\Skill;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);
});

it('lists the skill catalog', function () {
    $this->artisan('outwar:skills', ['action' => 'list'])
        ->assertSuccessful()
        ->expectsOutputToContain('Stealth');
});

it('selects and shows a cast-on-start skill', function () {
    $character = Character::factory()->for(Rga::factory())->create();

    $this->artisan('outwar:skills', ['action' => 'select', 'character' => $character->name, '--skill' => '4'])
        ->assertSuccessful();

    expect(CharacterSkill::where('character_id', $character->id)->where('skill_id', 4)->value('cast_on_start'))->toBeTrue();

    $this->artisan('outwar:skills', ['action' => 'show', 'character' => $character->name])
        ->assertSuccessful()
        ->expectsOutputToContain('Stealth');
});

it('deselects a skill', function () {
    $character = Character::factory()->for(Rga::factory())->create();
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => 4, 'cast_on_start' => true]);

    $this->artisan('outwar:skills', ['action' => 'deselect', 'character' => $character->name, '--skill' => 'Stealth'])
        ->assertSuccessful();

    expect(CharacterSkill::where('character_id', $character->id)->value('cast_on_start'))->toBeFalse();
});

it('casts one skill via the cast command', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    $this->artisan('outwar:cast', ['character' => $character->name, '--skill' => '4'])
        ->assertSuccessful()
        ->expectsOutputToContain('Cast Stealth');
});

it('casts the on-start set via the cast command', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => 4, 'cast_on_start' => true]);

    Http::fake(['*cast_skills.php*' => Http::response('Status: You just cast Stealth')]);

    $this->artisan('outwar:cast', ['character' => $character->name, '--on-start' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Cast 1 cast-on-start');
});
