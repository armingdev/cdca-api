<?php

use App\Game\Enums\SkillSchool;
use App\Game\Skills\SkillSyncService;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Rga;
use App\Models\Skill;
use Database\Seeders\SkillSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    (new SkillSeeder)->run();
});

/** A minimal cast_skills.php tab with the given skill rows. */
function skillsTabHtml(string $rows = ''): string
{
    return "<html><ul class=\"list-group\">{$rows}</ul></html>";
}

function skillRowHtml(int $id, string $name, string $levels, bool $trainable): string
{
    $train = $trainable ? "<a href=\"cast_skills.php?C=2&T={$id}\" >Train</a>" : '';

    return "<li class=\"list-group-item\" onclick=\"loadskill({$id});\">"
        ."<h6 class=\"tx-inverse\">{$name} ({$levels})</h6>"
        ."<p class=\"mg-b-0\">desc</p>{$train}</li>";
}

function fakeSkillTabs(array $overrides = []): void
{
    $responses = array_merge([
        'class' => gameFixture('skills/cast_skills_page.html'),
        'C4' => skillsTabHtml(),
        'C5' => skillsTabHtml(),
        'C6' => skillsTabHtml(),
        'C7' => gameFixture('skills/cast_skills_misc_tab.html'),
        'info' => gameFixture('skills/skills_info_misc_triworld.html'),
    ], $overrides);

    Http::fake(function ($request) use ($responses) {
        $url = $request->url();

        if (str_contains($url, 'skills_info.php')) {
            return Http::response($responses['info']);
        }

        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $tab = isset($query['C']) ? "C{$query['C']}" : 'class';

        return Http::response($responses[$tab] ?? $responses['class']);
    });
}

it('syncs trained and bonus levels from all five tabs', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeSkillTabs();

    $result = SkillSyncService::forCharacter($character)->sync();

    expect($result->rowsSynced)->toBe(13)
        ->and($result->skillPoints)->toBe(15)
        ->and($result->school)->toBeNull();

    $state = fn (int $id) => CharacterSkill::where('character_id', $character->id)->where('skill_id', $id)->first();

    expect($state(3)->trained_level)->toBe(1)
        ->and($state(3)->bonus_level)->toBe(8)
        ->and($state(3)->effectiveLevel())->toBe(9)
        ->and($state(7)->trained_level)->toBe(0)
        ->and($state(7)->isCastable())->toBeFalse()
        ->and($state(46)->trained_level)->toBe(1)
        ->and($character->fresh()->skill_points)->toBe(15);

    // Teleport: trained, no Train link, Class tab → single-level.
    expect(Skill::find(27)->single_level)->toBeTrue();
});

it('stores server-read buff windows from the Current Effects panel', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeSkillTabs();

    $result = SkillSyncService::forCharacter($character)->sync();

    expect($result->activeBuffs)->toBe(1);

    $empower = CharacterSkill::where('character_id', $character->id)->where('skill_id', 3)->first();

    expect($empower->buff_until)->not->toBeNull()
        ->and(now()->diffInMinutes($empower->buff_until))->toBeGreaterThan(165)
        ->and($empower->isBuffActive())->toBeTrue();

    // Skills without an active effect have no buff window.
    expect(CharacterSkill::where('character_id', $character->id)->where('skill_id', 4)->first()->buff_until)->toBeNull();
});

it('discovers unknown misc skills and upserts them without cooldown or duration', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Skill::find(3197)->delete();

    fakeSkillTabs();

    $result = SkillSyncService::forCharacter($character)->sync();

    $discovered = Skill::find(3197);

    expect($result->skillsDiscovered)->toBe(1)
        ->and($discovered->school)->toBe(SkillSchool::Misc)
        ->and($discovered->rage_cost)->toBe(500)
        ->and($discovered->cooldown_minutes)->toBeNull()
        ->and($discovered->duration_minutes)->toBeNull();
});

it('derives the committed school from trained school skills', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeSkillTabs(['C4' => skillsTabHtml(skillRowHtml(3008, 'Circumspect', '2+1', true))]);

    $result = SkillSyncService::forCharacter($character)->sync();

    expect($result->school)->toBe(SkillSchool::Ferocity)
        ->and($character->fresh()->school)->toBe(SkillSchool::Ferocity);
});

it('refreshes one skill with the authoritative recharge window', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*skills_info.php*' => Http::response(gameFixture('skills/skills_info_trained_recharging.html'))]);

    $info = SkillSyncService::forCharacter($character)->refreshSkillInfo(Skill::find(3));

    expect($info->rechargingMinutesRemaining)->toBe(111);

    $state = CharacterSkill::where('character_id', $character->id)->where('skill_id', 3)->first();

    expect($state->current_rage_cost)->toBe(90)
        ->and($state->current_cooldown_minutes)->toBe(120)
        ->and($state->current_duration_minutes)->toBe(180)
        ->and($state->recharge_until)->not->toBeNull()
        ->and($state->isOnCooldown())->toBeTrue();
});

it('trains a skill via C=2&T= and confirms through the skill log', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create(['skill_points' => 5]);
    Skill::find(27)->update(['single_level' => false]); // not yet trained here

    Http::fake(['*cast_skills.php*' => Http::response(gameFixture('skills/cast_skills_page.html'))]);

    $result = SkillSyncService::forCharacter($character)->train(Skill::find(27));

    expect($result->success)->toBeTrue()
        ->and($result->newLevel)->toBe(1)
        ->and($result->skillPointsRemaining)->toBe(15);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'cast_skills.php')
        && $request['C'] == 2
        && $request['T'] == 27);

    $state = CharacterSkill::where('character_id', $character->id)->where('skill_id', 27)->first();

    expect($state->trained_level)->toBe(1);
});

it('fails training when the game does not confirm it', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create(['skill_points' => 5]);

    Http::fake(['*cast_skills.php*' => Http::response(skillsTabHtml())]);

    $result = SkillSyncService::forCharacter($character)->train(Skill::find(4));

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('did not confirm');
});

it('refuses to train misc, gated, exhausted, cross-school, and maxed skills', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create([
        'skill_points' => 5,
        'level' => 50,
        'school' => SkillSchool::Ferocity,
    ]);

    $sync = SkillSyncService::forCharacter($character);

    // Misc skills are acquired, not trained.
    expect($sync->train(Skill::find(46))->success)->toBeFalse();

    // Masterful Ferocity unlocks at 80; character is 50.
    expect($sync->train(Skill::find(3182))->message)->toContain('unlocks at character level 80');

    // Cross-school: committed to Ferocity, Hitman is Affliction.
    expect($sync->train(Skill::find(36))->message)->toContain('committed to ferocity');

    // Single-level skill already trained.
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => 27, 'trained_level' => 1]);
    expect($sync->train(Skill::find(27))->message)->toContain('single-level');

    // No skill points left.
    $character->update(['skill_points' => 0]);
    expect(SkillSyncService::forCharacter($character->fresh())->train(Skill::find(4))->message)
        ->toContain('No skill points');

    Http::assertNothingSent();
});
