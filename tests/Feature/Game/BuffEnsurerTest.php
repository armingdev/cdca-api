<?php

use App\Game\Data\BuffEnsureResult;
use App\Game\Skills\BuffEnsurer;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Rga;
use App\Models\Skill;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    $this->character = Character::factory()->for(Rga::factory()->withSession())->create();
});

function selectSkill(Character $character, Skill $skill, array $state = []): CharacterSkill
{
    return CharacterSkill::create(array_merge([
        'character_id' => $character->id,
        'skill_id' => $skill->id,
        'cast_on_start' => true,
        'trained_level' => 1,
    ], $state));
}

it('casts every selected skill, not just the first few', function () {
    fakeSkillWorld();

    for ($i = 1; $i <= 9; $i++) {
        selectSkill($this->character, makeSkill(100 + $i, "Buff {$i}", 60, 60));
    }

    $result = BuffEnsurer::forCharacter($this->character)->ensure();

    expect($result->castCount())->toBe(9)
        ->and($result->skipped)->toBe([])
        ->and($result->failed)->toBe([]);

    expect(collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'cast_skills.php') && $pair[0]->method() === 'POST')
        ->count())->toBe(9);
});

it('re-casts a skill the game reports inactive even though the local estimate says otherwise', function () {
    // Duration (180m) outlives the cooldown (120m) — the shape that made the
    // local estimate claim "still active" for a buff that had long since gone.
    $empower = makeSkill(3, 'Empower', 120, 180);
    selectSkill($this->character, $empower, ['last_cast_at' => now()->subMinutes(150)]);

    // The game's Current Effects panel does not list it, and it is off cooldown.
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => '5,000', 'level' => '60', 'width' => 0]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response('<div><h5>Empower - Level 1</h5>x</div>'
                .'<b>Rage Cost:</b><br> 10 <b>Cooldown:</b><br> 120 mins <b>Duration:</b><br> 180 mins');
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : '<html><body><b>Skill:</b></td><td>0</td></body></html>');
        }

        return Http::response('<html></html>');
    });

    $result = BuffEnsurer::forCharacter($this->character)->ensure();

    expect($result->castCount())->toBe(1);
});

it('treats a fresh info read with no recharge notice as ready', function () {
    $stealth = makeSkill(4, 'Stealth', 600, 60);
    // Cast 5 minutes ago, so the local estimate would call it on cooldown for
    // another ten hours — but the game says it is not recharging.
    selectSkill($this->character, $stealth, ['last_cast_at' => now()->subMinutes(5)]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => '5,000', 'level' => '60', 'width' => 0]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response('<div><h5>Stealth - Level 1</h5>x</div>'
                .'<b>Rage Cost:</b><br> 10 <b>Cooldown:</b><br> 600 mins <b>Duration:</b><br> 60 mins');
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : '<html><body><b>Skill:</b></td><td>0</td></body></html>');
        }

        return Http::response('<html></html>');
    });

    expect(BuffEnsurer::forCharacter($this->character)->ensure()->castCount())->toBe(1);
});

it('skips a skill it cannot pay for but still casts the cheaper ones', function () {
    fakeSkillWorld(rage: 100);

    selectSkill($this->character, makeSkill(5, 'Expensive', 60, 60, rage: 500));
    selectSkill($this->character, makeSkill(6, 'Cheap', 60, 60, rage: 20));

    $result = BuffEnsurer::forCharacter($this->character)->ensure();

    expect($result->cast)->toHaveCount(1)
        ->and($result->cast[0]['name'])->toBe('Cheap')
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0])->toMatchArray([
            'name' => 'Expensive',
            'reason' => BuffEnsureResult::REASON_RAGE,
        ]);
});

it('does not accept a confirmation naming a different skill', function () {
    selectSkill($this->character, makeSkill(4, 'Stealth', 60, 60));

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => '5,000', 'level' => '60', 'width' => 0]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml(4));
        }

        if (str_contains($url, 'cast_skills.php')) {
            // A status line left over from an earlier cast of another skill.
            return Http::response($request->method() === 'POST'
                ? 'Status: You just cast Fortify.'
                : '<html><body><b>Skill:</b></td><td>0</td></body></html>');
        }

        return Http::response('<html></html>');
    });

    $result = BuffEnsurer::forCharacter($this->character)->ensure();

    expect($result->cast)->toBe([])
        ->and($result->failed)->toHaveCount(1)
        ->and(CharacterSkill::where('skill_id', 4)->value('last_cast_at'))->toBeNull();
});

it('skips a synced untrained skill without a request', function () {
    fakeSkillWorld();

    selectSkill($this->character, makeSkill(7, 'On Guard', 60, 60), [
        'trained_level' => 0,
        'bonus_level' => 8,
        'synced_at' => now(),
    ]);

    $result = BuffEnsurer::forCharacter($this->character)->ensure();

    expect($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]['reason'])->toBe(BuffEnsureResult::REASON_UNTRAINED);

    expect(collect(Http::recorded())
        ->filter(fn (array $pair) => $pair[0]->method() === 'POST')
        ->count())->toBe(0);
});

it('costs nothing to call again while every buff is healthy', function () {
    fakeSkillWorld();

    selectSkill($this->character, makeSkill(4, 'Stealth', 60, 60));

    $ensurer = BuffEnsurer::forCharacter($this->character);
    $ensurer->ensure();

    $afterFirstPass = count(Http::recorded());

    // The engine calls this before every attack; it must not talk to the game
    // again until something could actually have changed.
    $ensurer->ensure();
    $ensurer->ensure();

    expect(count(Http::recorded()))->toBe($afterFirstPass);
});

it('still casts when the skill sync itself fails to parse', function () {
    selectSkill($this->character, makeSkill(4, 'Stealth', 60, 60));

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => '5,000', 'level' => '60', 'width' => 0]));
        }

        // A malformed info page used to throw straight out of the pre-sync and
        // fail the whole run.
        if (str_contains($url, 'skills_info.php')) {
            return Http::response('<html>maintenance</html>');
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : '<html><body><b>Skill:</b></td><td>0</td></body></html>');
        }

        return Http::response('<html></html>');
    });

    expect(BuffEnsurer::forCharacter($this->character)->ensure()->castCount())->toBe(1);
});
