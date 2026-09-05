<?php

use App\Game\Enums\RunMode;
use App\Jobs\RunBrawlJob;
use App\Jobs\RunJob;
use App\Jobs\RunMobJob;
use App\Jobs\RunPvpJob;
use App\Jobs\RunQuestJob;
use App\Jobs\RunQuestListJob;
use App\Models\CharacterSkill;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\Room;
use App\Models\RunParticipant;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Nothing in the suite may reach the live game servers: an un-faked request
    // throws instead of hitting sigil/torax with a real character's session.
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Load a captured game-response fixture (sanitized copies of
 * docs/game-api/samples/).
 */
function gameFixture(string $name): string
{
    return file_get_contents(__DIR__.'/Fixtures/game/'.$name);
}

/**
 * Seed the Circumspect skill row the run engine gates every cycle on. Shared:
 * a helper used by more than one test file must live here, or it disappears
 * when the suite is sharded across parallel processes.
 */
function seedCircumspect(): Skill
{
    return Skill::create([
        'id' => Skill::CIRCUMSPECT_ID,
        'name' => 'Circumspect',
        'school' => 'ferocity',
        'rage_cost' => 20,
        'cooldown_minutes' => 720,
        'duration_minutes' => 60,
    ]);
}

/**
 * Build the mode-appropriate run job for a participant with a freshly minted
 * dispatch token (mirrors RunDispatcher), for tests that run jobs inline.
 */
function makeRunJob(RunParticipant $participant): RunJob
{
    $token = (string) Str::uuid();
    $participant->update(['dispatch_token' => $token]);

    return match ($participant->run->mode) {
        RunMode::Mob => new RunMobJob($participant, $token),
        RunMode::Quest => new RunQuestJob($participant, $token),
        RunMode::QuestList => new RunQuestListJob($participant, $token),
        RunMode::PvpBrawl, RunMode::PvpFactionBrawl => new RunBrawlJob($participant, $token),
        RunMode::PvpAttackList,
        RunMode::PvpCrewHitlist,
        RunMode::PvpCrewMembers => new RunPvpJob($participant, $token),
    };
}

/** One accounts.php row in the captured shape (name/level/crew font cells + PLAY! link). */
function sigilAccountsHtml(int $level = 85): string
{
    return <<<HTML
    <table><tr>
      <td><font color="#FFFF00"><b>RealLinuXX</b></font></td>
      <td><font color="#FFFFFF"><b>{$level}</b></font></td>
      <td><font color="#999999"><b>Collective 2</b></font></td>
      <td><a href="http://sigil.outwar.com/world.php?suid=2403&serverid=1"><b>PLAY!</b></a></td>
    </tr></table>
    HTML;
}

/** A catalog skill with explicit cooldown/duration/rage, for skill tests. */
function makeSkill(int $id, string $name, int $cooldown, int $duration, int $rage = 10): Skill
{
    return Skill::create([
        'id' => $id, 'name' => $name, 'school' => 'class',
        'rage_cost' => $rage, 'cooldown_minutes' => $cooldown, 'duration_minutes' => $duration,
    ]);
}

/**
 * A faithful skills-only fake: the five cast_skills.php tabs (with a Current
 * Effects panel built from real cast times), per-skill skills_info.php pages
 * carrying the game's recharge line, and userstats.php for the rage budget.
 */
function fakeSkillWorld(int $rage = 5000): void
{
    Http::fake(function ($request) use ($rage) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode([
                'exp' => '1,000', 'rage' => number_format($rage), 'level' => '60', 'width' => 0,
            ]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : fakeSkillsPageHtml());
        }

        return Http::response('<html></html>');
    });
}

/**
 * A skills_info.php page for one skill, carrying the game's authoritative
 * "recharging, {n} minutes remaining" line whenever the skill's own cast
 * bookkeeping says it is still on cooldown.
 *
 * Reporting a skill as ready is a statement, not a blank: the engine trusts
 * it over its local estimate, so a fixture that never mentions recharging
 * tells every test that every cooldown has elapsed.
 *
 * Defaults to Circumspect's shape when the id is unknown.
 */
function fakeSkillInfoHtml(?int $skillId = null): string
{
    $skill = $skillId !== null ? Skill::find($skillId) : null;
    $name = $skill?->name ?? 'Circumspect';
    $cooldown = $skill?->cooldown_minutes ?? 720;
    $duration = $skill?->duration_minutes ?? 60;
    $rage = $skill?->rage_cost ?? 20;

    $recharging = '';

    if ($skill !== null) {
        $state = CharacterSkill::where('skill_id', $skill->id)->first();
        $endsAt = $state?->last_cast_at?->addMinutes($state->current_cooldown_minutes ?? $cooldown ?? 0);

        if ($endsAt !== null && $endsAt->isFuture()) {
            $minutes = (int) ceil(now()->diffInMinutes($endsAt, true));
            $recharging = " This skill is recharging. {$minutes} minutes remaining.";
        }
    }

    return "<div><h5>{$name} - Level 1</h5>Reduces the rage cost of fighting.</div>"
        ."<b>Rage Cost:</b><br> {$rage} <b>Cooldown:</b><br> {$cooldown} mins <b>Duration:</b><br> {$duration} mins"
        .$recharging;
}

/**
 * A cast_skills.php tab whose Current Effects panel reflects what is actually
 * buffed, derived from each skill's own cast bookkeeping rather than from our
 * sync stamps.
 *
 * Fidelity matters here: an empty panel is not "no information", it is the
 * game stating that nothing is active, and the engine now (correctly) trusts
 * that over its local estimate. A fake that always returned an empty page
 * would tell every test that every buff had lapsed.
 */
function fakeSkillsPageHtml(): string
{
    $entries = '';

    foreach (CharacterSkill::with('skill')->get() as $state) {
        // The cast time is the game's own ground truth for the window. Reading
        // back buff_until would feed our derived column into the fake that
        // sets it, and rounding would then nudge the expiry forward on every
        // sync — a buff that can never lapse.
        $endsAt = $state->last_cast_at !== null
            ? $state->last_cast_at->addMinutes($state->current_duration_minutes ?? $state->skill?->duration_minutes ?? 0)
            : $state->buff_until;

        if ($endsAt === null || $endsAt->isPast()) {
            continue;
        }

        $minutes = max(1, (int) floor(now()->diffInMinutes($endsAt, true)));
        $level = max(1, $state->trained_level + $state->bonus_level);

        $entries .= "<a onmouseover=\"popup(event,'<b>Level {$level} {$state->skill->name}</b>"
            ."<br>{$minutes} mins left<br>Cast By Tester'\">effect</a>";
    }

    return '<html><body><b>Skill:</b></td><td>0</td>'.$entries.'</body></html>';
}

/**
 * The game's confirmation for the skill actually requested. The engine now
 * name-matches it, because a stale status line from an earlier cast used to
 * be read as success and then blocked the retry.
 */
function fakeCastConfirmationHtml(?int $skillId): string
{
    $name = $skillId !== null ? Skill::find($skillId)?->name : null;

    return 'Status: You just cast '.($name ?? 'a skill').'.';
}

/**
 * Stateful fake game for combat tests: two mapped rooms (1 –E– 2), a Kix
 * Harvester in room 2 that dies after one successful attack, configurable
 * rage. Pair with rooms/mob seeded via seedCombatWorld().
 *
 * Returns a respawn closure that stands the Harvester back up — re-faking
 * cannot do it, since Http::fake callbacks stack first-wins.
 */
function fakeCombatWorld(int $rage = 5000): Closure
{
    $position = 1;
    $killed = false;

    $roomBlob = function (int $roomId) use (&$killed): string {
        $mobs = $roomId === 2 ? [[
            'name' => 'Kix Harvester',
            'level' => '60',
            'rage' => '150',
            'h' => 'hash',
            'encid' => 'FRESH'.random_int(1, 9999),
            'mobId' => '777',
            'spawnId' => '1234',
            'image' => 'mobs/kix.jpg',
            'isDead' => $killed,
            'type' => 0,
            'lastKilledBy' => null,
            'canForm' => false,
        ]] : [];

        return json_encode([
            'error' => '',
            'curRoom' => (string) $roomId,
            'name' => "Room {$roomId}",
            'north' => '0',
            'east' => $roomId === 1 ? '2' : '0',
            'south' => '0',
            'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs,
            'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$killed, $roomBlob, $rage) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode([
                'exp' => '1,000', 'rage' => number_format($rage), 'level' => '60', 'width' => 0,
            ]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : fakeSkillsPageHtml());
        }

        if (str_contains($url, 'somethingelse.php')) {
            $killed = true;

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/555/']);
        }

        if (str_contains($url, 'attack/555')) {
            return Http::response(
                'var battle_result = "Hero gained 2 strength<br>Hero has gained 950 experience!<br>Hero gained 55 gold!";'
                .'var attacker_name = "Hero"; var defender_name = "Kix Harvester";'
                .'<div id="found_items"><b>WIN: Found Kix Potion</b></div>'
            );
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $target = (int) $query['room'] ?: $position;
            $position = $target;

            return Http::response($roomBlob($target));
        }

        return Http::response('<html>world page</html>');
    });

    return function () use (&$killed): void {
        $killed = false;
    };
}

/**
 * The battle page a lost fight serves: the captured loss shape — the mob
 * weakened us, and there is no exp gain anywhere.
 */
function losingBattleHtml(string $mobName): string
{
    return "var battle_result = \"{$mobName} has weakened Hero by 2<br>\";"
        .'var attacker_name = "Hero"; var defender_name = "'.$mobName.'";';
}

/**
 * Combat world where the target always wins: every attack answers with the
 * captured loss shape and the mob never dies. Each attack still costs rage, so
 * a run without smart mode grinds down to the floor exactly as it does today.
 * $levelUps caps how many times levelup.php reports success, so a test can
 * hand smart mode a level or refuse it one. The backpack is empty, making gear
 * passes harmless no-ops. Pair with seedCombatWorld().
 */
function fakeLosingWorld(int $rage = 50000, int $levelUps = 0): void
{
    $level = 10;
    $position = 1;

    $roomBlob = function (int $roomId): string {
        $mobs = $roomId === 2
            ? [questMobJson('Kix Harvester', 777, 1234, 'hash', level: 60)]
            : [];

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0', 'east' => $roomId === 1 ? '2' : '0', 'south' => '0', 'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs, 'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$level, &$levelUps, &$rage, $roomBlob) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode([
                'exp' => '1,000', 'rage' => number_format($rage), 'level' => (string) $level, 'width' => 0,
            ]));
        }

        if (str_contains($url, 'levelup.php')) {
            if ($levelUps <= 0) {
                return Http::response('<html>You do not have enough experience to level up.</html>');
            }

            $levelUps--;
            $level++;
            $rage = 50000;

            return Http::response(gameFixture('levelup_success.html'));
        }

        if (str_contains($url, 'backpackcontents.php')) {
            return Http::response(gameFixture('backpack_contents_empty.html'));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'somethingelse.php')) {
            $rage = max(0, $rage - 150);

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/606/']);
        }

        if (str_contains($url, 'attack/606')) {
            return Http::response(losingBattleHtml('Kix Harvester'));
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            return Http::response($roomBlob($position));
        }

        return Http::response('<html>world page</html>');
    });
}

/**
 * Quest 742's world where the kill-objective mob always wins: the step never
 * completes because no kill lands, so smart mode's loss handling decides the
 * outcome. Level-ups are unavailable and the backpack is empty.
 */
function fakeLosingQuestWorld(int $rage = 50000): void
{
    $position = 1;

    $roomBlob = function (int $roomId): string {
        $mobs = match ($roomId) {
            1 => [questMobJson('Stella', 59293, 888, 'npchash', level: 10)],
            2 => [questMobJson('Street Crawler', 4000, 5000, 'x', level: 20)],
            default => [],
        };

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0', 'east' => $roomId === 1 ? '2' : '0', 'south' => '0', 'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs, 'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$rage, $roomBlob) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => number_format($rage), 'level' => '20', 'width' => 0]));
        }

        if (str_contains($url, 'levelup.php')) {
            return Http::response('<html>You do not have enough experience to level up.</html>');
        }

        if (str_contains($url, 'backpackcontents.php')) {
            return Http::response(gameFixture('backpack_contents_empty.html'));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'world_questHelper.php')) {
            // Offered fresh at the giver: nothing in progress on the character.
            return Http::response(questHelperJson([]));
        }

        if (str_contains($url, 'mob_talk.php')) {
            return Http::response(gameFixture('quest/mob_talk_kill_incomplete.html'));
        }

        if (str_contains($url, 'mob.php')) {
            return Http::response('<div><a href="mob_talk.php?id=59293&stepid=3378&userspawn=&questid=742">Street Crawler</a></div>');
        }

        if (str_contains($url, 'somethingelse.php')) {
            $rage = max(0, $rage - 150);

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/908/']);
        }

        if (str_contains($url, 'attack/908')) {
            return Http::response(losingBattleHtml('Street Crawler'));
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            return Http::response($roomBlob($position));
        }

        return Http::response('<html>world</html>');
    });
}

/**
 * DB side of the fake combat world: mapped rooms + the target mob.
 */
function seedCombatWorld(): void
{
    Room::factory()->create(['id' => 1, 'east' => 2]);
    Room::factory()->create(['id' => 2, 'west' => 1]);
    Mob::factory()
        ->create(['name' => 'Kix Harvester'])
        ->rooms()->attach(2, ['last_seen_at' => now()]);
}

/**
 * DB side of the fake quest world: room 1 (Stella, quest-giver) –E– room 2
 * (Street Crawler, the kill-objective mob).
 */
function seedQuestWorld(): void
{
    Room::factory()->create(['id' => 1, 'east' => 2]);
    Room::factory()->create(['id' => 2, 'west' => 1]);
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
}

/**
 * DB side of the resumed-quest world, on top of seedQuestWorld(): the quest's
 * catalog giver (Sgt. Neatham) stands in room 3, two rooms from Stella, who
 * holds the step the character has actually reached.
 */
function seedResumedQuestWorld(): void
{
    Room::whereKey(2)->update(['east' => 3]);
    Room::factory()->create(['id' => 3, 'west' => 2]);
    Mob::factory()->create(['name' => 'Sgt. Neatham'])->rooms()->attach(3, ['last_seen_at' => now()]);
}

/**
 * A quest already several steps in: quest 743 was given by Sgt. Neatham, but
 * the character's current step (3380) belongs to Stella, so Neatham's popup
 * offers nothing at all.
 *
 * This is the shape that used to be read as "already completed" and written to
 * the progress ledger, skipping quests that were merely under way. Only the
 * tracker knows better, and here it names Stella.
 *
 * $needsKills puts an unmet kill objective on the step first, so the tracker
 * asks for 5 Street Crawlers before anyone will talk. $offeredAtStella false
 * is the pathological case where the tracker lists the quest but no mob will
 * open it.
 */
function fakeResumedQuestWorld(bool $needsKills = false, bool $offeredAtStella = true): void
{
    $position = 1;
    $killed = 0;
    $finished = false;

    $roomBlob = function (int $roomId) use (&$killed): string {
        $mobs = match ($roomId) {
            1 => [questMobJson('Stella', 59293, 888, 'npchash', level: 10)],
            2 => [questMobJson('Street Crawler', 4000, 5000, 'x', level: 20, isDead: $killed >= 5)],
            3 => [questMobJson('Sgt. Neatham', 4500, 999, 'givhash', level: 10)],
            default => [],
        };

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0',
            'east' => $roomId === 1 ? '2' : ($roomId === 2 ? '3' : '0'),
            'south' => '0',
            'west' => $roomId === 2 ? '1' : ($roomId === 3 ? '2' : '0'),
            'roomDetailsNew' => $mobs, 'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$killed, &$finished, $roomBlob, $needsKills, $offeredAtStella) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => '50,000', 'level' => '20', 'width' => 0]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : fakeSkillsPageHtml());
        }

        if (str_contains($url, 'world_questHelper.php')) {
            if ($finished) {
                return Http::response(questHelperJson([]));
            }

            $rows = $needsKills
                ? [['type' => 'kill', 'target' => 'Street Crawler', 'current' => min($killed, 5), 'required' => 5, 'mobId' => 4000, 'conditionId' => 77]]
                : [];

            // The tracker names the mob holding the step only once every
            // count on it is in — exactly as the live game does.
            if (! $needsKills || $killed >= 5) {
                $rows[] = ['type' => 'talk', 'target' => 'Stella', 'mobId' => 868];
            }

            return Http::response(questHelperJson([[
                'questId' => 743,
                'name' => 'Cleansing the Church',
                'stepId' => 3380,
                'rows' => $rows,
            ]]));
        }

        if (str_contains($url, 'mob_talk.php')) {
            if (str_contains($url, 'finish=1')) {
                $finished = true;

                return Http::response(gameFixture('quest/mob_talk_step_finish.html'));
            }

            return Http::response(gameFixture('quest/mob_talk_kill_complete.html'));
        }

        if (str_contains($url, 'mob.php')) {
            // Stella holds the current step; the catalog giver has nothing to
            // say about a quest that has moved past him.
            $offered = str_contains($url, 'id=888') && $offeredAtStella;

            return Http::response($offered
                ? '<div><a href="mob_talk.php?id=59293&stepid=3380&userspawn=&questid=743">Cleansing the Church</a></div>'
                : '<div>Nothing for you today.</div>');
        }

        if (str_contains($url, 'somethingelse.php')) {
            $killed++;

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/900/']);
        }

        if (str_contains($url, 'attack/900')) {
            return Http::response('var battle_result = "Hero has gained 500 experience!"; var defender_name = "Street Crawler";');
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            return Http::response($roomBlob($position));
        }

        return Http::response('<html>world</html>');
    });
}

/**
 * DB side of the multi-objective quest world, on top of seedQuestWorld():
 * room 1 (Stella) –E– room 2 (Street Crawler) –E– room 3 (Alley Rat), so a
 * single step can demand kills of two different mobs in two different places.
 */
function seedMultiObjectiveQuestWorld(): void
{
    // Extends seedQuestWorld(): room 3 and its Alley Rat, reached east of the
    // Street Crawler room.
    Room::whereKey(2)->update(['east' => 3]);
    Room::factory()->create(['id' => 3, 'west' => 2]);
    Mob::factory()->create(['name' => 'Alley Rat'])->rooms()->attach(3, ['last_seen_at' => now()]);
}

/**
 * A quest whose single step wants 5 Street Crawlers *and* 3 Alley Rats, served
 * from the captured multi-objective fixtures and keyed on two independent kill
 * counters. The step only offers its finish link once both are satisfied.
 *
 * @param  bool  $ratsFarmable  false leaves the Alley Rat unmapped, the case where one
 *                              objective of a step simply cannot be worked
 */
function fakeMultiObjectiveQuestWorld(int $rage = 50000, bool $ratsFarmable = true): void
{
    $position = 1;
    $crawlers = 0;
    $rats = 0;

    $roomBlob = function (int $roomId) use (&$crawlers, &$rats): string {
        $mobs = match ($roomId) {
            1 => [questMobJson('Stella', 59293, 888, 'npchash', level: 10)],
            2 => [questMobJson('Street Crawler', 4000, 5000, 'x', level: 20, isDead: $crawlers >= 5)],
            3 => [questMobJson('Alley Rat', 4100, 5100, 'y', level: 20, isDead: $rats >= 3)],
            default => [],
        };

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0',
            'east' => $roomId === 1 ? '2' : ($roomId === 2 ? '3' : '0'),
            'south' => '0',
            'west' => $roomId === 2 ? '1' : ($roomId === 3 ? '2' : '0'),
            'roomDetailsNew' => $mobs, 'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$crawlers, &$rats, $roomBlob, $rage, $ratsFarmable) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode([
                'exp' => '1,000', 'rage' => number_format($rage), 'level' => '20', 'width' => 0,
            ]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'world_questHelper.php')) {
            // Offered fresh at the giver: nothing in progress on the character.
            return Http::response(questHelperJson([]));
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : fakeSkillsPageHtml());
        }

        if (str_contains($url, 'mob_talk.php')) {
            if (str_contains($url, 'finish=1')) {
                return Http::response(gameFixture('quest/mob_talk_step_finish.html'));
            }

            // Both objectives met is the only state that earns a finish link.
            if ($crawlers >= 5 && ($rats >= 3 || ! $ratsFarmable)) {
                return Http::response(gameFixture($ratsFarmable
                    ? 'quest/mob_talk_kill_complete.html'
                    : 'quest/mob_talk_multi_objective_all_complete.html'));
            }

            return Http::response(gameFixture($crawlers >= 5
                ? 'quest/mob_talk_multi_objective_one_complete.html'
                : 'quest/mob_talk_multi_objective_incomplete.html'));
        }

        if (str_contains($url, 'mob.php')) {
            return Http::response('<div><a href="mob_talk.php?id=59293&stepid=3378&userspawn=&questid=742">Street Crawler</a></div>');
        }

        if (str_contains($url, 'somethingelse.php')) {
            $position === 3 ? $rats++ : $crawlers++;

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/900/']);
        }

        if (str_contains($url, 'attack/900')) {
            return Http::response('var battle_result = "Hero has gained 500 experience!"; var defender_name = "Street Crawler";');
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            return Http::response($roomBlob($position));
        }

        return Http::response('<html>world</html>');
    });
}

/**
 * A two-step quest whose *intermediate* turn-in page carries no onward link —
 * the shape that made the runner declare the whole quest complete after step
 * one. The giver keeps offering the quest until both steps are turned in.
 */
function fakeMultiStepQuestWorld(int $rage = 50000): void
{
    $position = 1;
    $killed = 0;
    $stepsFinished = 0;

    $roomBlob = function (int $roomId) use (&$killed): string {
        $mobs = match ($roomId) {
            1 => [questMobJson('Stella', 59293, 888, 'npchash', level: 10)],
            2 => [questMobJson('Street Crawler', 4000, 5000, 'x', level: 20, isDead: $killed >= 5)],
            default => [],
        };

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0', 'east' => $roomId === 1 ? '2' : '0', 'south' => '0', 'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs, 'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$killed, &$stepsFinished, $roomBlob, $rage) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode([
                'exp' => '1,000', 'rage' => number_format($rage), 'level' => '20', 'width' => 0,
            ]));
        }

        if (str_contains($url, 'world_questHelper.php')) {
            // The tracker is the only thing that knows the quest is not over
            // after step one: its turn-in page carries no onward link, so
            // without this the runner would call the quest complete there.
            return Http::response(questHelperJson($stepsFinished === 1 ? [[
                'questId' => 742,
                'name' => 'Street Crawler',
                'stepId' => 3379,
                'rows' => [['type' => 'talk', 'target' => 'Stella', 'mobId' => 868]],
            ]] : []));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : fakeSkillsPageHtml());
        }

        if (str_contains($url, 'mob_talk.php')) {
            if (str_contains($url, 'finish=1')) {
                $stepsFinished++;

                // No mob_talk link on the way out, for either step.
                return Http::response(gameFixture('quest/mob_talk_step_finish.html'));
            }

            // Step two is a talk-only step: finishable the moment it is opened.
            return Http::response(gameFixture($stepsFinished >= 1 || $killed >= 5
                ? 'quest/mob_talk_kill_complete.html'
                : 'quest/mob_talk_kill_incomplete.html'));
        }

        if (str_contains($url, 'mob.php')) {
            // The giver stops offering the quest only once both steps are in.
            if ($stepsFinished >= 2) {
                return Http::response('<div>Nothing for you today.</div>');
            }

            $stepId = $stepsFinished === 0 ? 3378 : 3379;

            return Http::response("<div><a href=\"mob_talk.php?id=59293&stepid={$stepId}&userspawn=&questid=742\">Street Crawler</a></div>");
        }

        if (str_contains($url, 'somethingelse.php')) {
            $killed++;

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/900/']);
        }

        if (str_contains($url, 'attack/900')) {
            return Http::response('var battle_result = "Hero has gained 500 experience!"; var defender_name = "Street Crawler";');
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            return Http::response($roomBlob($position));
        }

        return Http::response('<html>world</html>');
    });
}

/**
 * Catalog rows matching the fake quest world: quests 742/743, both given by
 * Stella. Returns them keyed by game quest id for addQuest() calls.
 *
 * @return array<int, Quest>
 */
function seedQuestCatalog(): array
{
    return [
        742 => Quest::factory()->create(['game_quest_id' => 742, 'name' => 'Street Crawler', 'giver' => 'Stella']),
        743 => Quest::factory()->create(['game_quest_id' => 743, 'name' => 'Cleansing the Church', 'giver' => 'Stella']),
    ];
}

/**
 * A `world_questHelper.php` body in the live tracker's markup: one
 * `<div align="center" class="mb-3">` per in-progress quest, wrapping a
 * `<table id="quest-{id}">` of objective rows.
 *
 * An empty list is the honest answer for a character with nothing in progress
 * — which is also what a quest still sitting unaccepted at its giver looks
 * like. Row shapes follow the capture: a kill/collect row carries the counter
 * and goes green at its target, a talk row carries no counter and names the
 * mob to visit.
 *
 * @param  list<array{questId: int, name?: string, stepId: int, rows: list<array<string, mixed>>}>  $quests
 */
function questHelperJson(array $quests): string
{
    $blocks = '';

    foreach ($quests as $quest) {
        $rows = '';

        foreach ($quest['rows'] as $row) {
            $type = $row['type'];
            $target = (string) $row['target'];
            $call = sprintf(
                "getQuestHelpData2('%d', '%d', '%s', '%d', '%d');",
                $quest['questId'],
                $row['mobId'] ?? 0,
                $type === 'collect' ? $target : '',
                $quest['stepId'],
                $row['conditionId'] ?? 0,
            );

            if ($type === 'talk') {
                $rows .= sprintf(
                    '<tr><td bgcolor="#FFFFFF" id="questStep_%d_0"><a href="javascript:void(0);" onClick="%s">'
                    .'<font color="#000000" face="Arial"><b>%s %s</a></b></font></td></tr>',
                    $quest['stepId'],
                    $call,
                    $row['verb'] ?? 'Find',
                    $target,
                );

                continue;
            }

            $current = (int) ($row['current'] ?? 0);
            $required = (int) ($row['required'] ?? 1);

            $rows .= sprintf(
                '<tr><td><font face="Arial" size="1"><font color="#A00000"><a href="javascript:void(0);" onClick="%s">'
                .'<font color="%s" face="Verdana, Arial, Helvetica, sans-serif"><b>%s</a>: </b>%d/%d%s</font></td></tr>',
                $call,
                $current >= $required ? '#008000' : '#EA2300',
                $target,
                $current,
                $required,
                $type === 'kill' ? ' killed' : '',
            );
        }

        $blocks .= sprintf(
            '<div align="center" class="mb-3"><table width="100%%" cellspacing="0" cellpadding="0"><tr><td colspan="2">'
            .'<table border="0"><tr><td><a href="show_quest.php?quest=%d"><img src="/images/questwiki.jpg" /></a>'
            .'<svg class="togglequestcollapse"></svg> <svg class="hidequest"></svg> %s</span></b></td></tr></table>'
            .'</td></tr><tr><td rowspan="2"><table id="quest-%d" border="0" class="wquesttable">%s</table>'
            .'</td></tr></table></div>',
            $quest['questId'],
            $quest['name'] ?? "Quest {$quest['questId']}",
            $quest['questId'],
            $rows,
        );
    }

    return json_encode(['qtable' => $blocks]);
}

function questMobJson(
    string $name,
    int $mobId,
    int $spawnId,
    string $hash,
    int $level,
    bool $isDead = false,
    int $rageCost = 100,
): array {
    return [
        'name' => $name, 'level' => (string) $level, 'rage' => (string) $rageCost, 'h' => $hash,
        'encid' => 'ENC'.$spawnId, 'mobId' => (string) $mobId, 'spawnId' => (string) $spawnId,
        'isDead' => $isDead, 'type' => 0, 'canForm' => false,
    ];
}

/**
 * Stateful fake of quest 742 (Street Crawler, step 3378): serves the real
 * captured mob_talk fixtures by kill count. Under 5 → incomplete (no finish
 * link); 5+ → complete (finish link); the finish href → the reward page.
 * Returns a setter to change the reported rage and/or the live-mob pool
 * mid-test (Http::fake callbacks stack first-wins, so re-faking cannot
 * override an earlier catch-all).
 *
 * $level is what userstats reports and $levelUps how many times levelup.php
 * succeeds (each raising the reported level) — enough to exercise smart mode's
 * "level up to the quest's required level" gate.
 *
 * $liveMobs caps how many Street Crawlers can be killed before the room runs
 * dry mid-objective. Raising it through the returned setter is a respawn.
 *
 * $clearedRendersCorpse picks how a cleared spawn room is rendered. True keeps
 * the mob with isDead — the shape of the one room blob we ever captured. False
 * drops the entry entirely, which is what the live game did in the runs that
 * reported "could not make progress" on a mob that was merely on its timer.
 *
 * $mobRage is the price the game puts on each Street Crawler, and
 * $attacksRefused makes somethingelse.php answer 200 with an empty body, the
 * refusal that carries no reason to parse.
 */
function fakeQuestWorld(
    int $rage = 50000,
    int $level = 20,
    int $levelUps = 0,
    int $liveMobs = PHP_INT_MAX,
    bool $clearedRendersCorpse = true,
    int $mobRage = 100,
    bool $attacksRefused = false,
): Closure {
    $position = 1;
    $killed = 0;

    $roomBlob = function (int $roomId) use (&$killed, &$liveMobs, $clearedRendersCorpse, $mobRage): string {
        $cleared = $killed >= $liveMobs;
        $crawler = $cleared && ! $clearedRendersCorpse
            ? []
            : [questMobJson('Street Crawler', 4000, 5000, 'x', level: 20, isDead: $cleared, rageCost: $mobRage)];

        $mobs = match ($roomId) {
            1 => [questMobJson('Stella', 59293, 888, 'npchash', level: 10)],
            2 => $crawler,
            default => [],
        };

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0', 'east' => $roomId === 1 ? '2' : '0', 'south' => '0', 'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs, 'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$killed, &$level, &$levelUps, $roomBlob, &$rage, $attacksRefused) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => number_format($rage), 'level' => (string) $level, 'width' => 0]));
        }

        if (str_contains($url, 'world_questHelper.php')) {
            // Quest 742 sits unaccepted at Stella, so the character has
            // nothing in progress — before the run or after its single step.
            return Http::response(questHelperJson([]));
        }

        if (str_contains($url, 'levelup.php')) {
            if ($levelUps <= 0) {
                return Http::response('<html>You do not have enough experience to level up.</html>');
            }

            $levelUps--;
            $level++;

            return Http::response(gameFixture('levelup_success.html'));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml((int) $request['id']));
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response($request->method() === 'POST'
                ? fakeCastConfirmationHtml((int) $request['castskillid'])
                : fakeSkillsPageHtml());
        }

        if (str_contains($url, 'mob_talk.php')) {
            if (str_contains($url, 'finish=1')) {
                return Http::response(gameFixture('quest/mob_talk_step_finish.html'));
            }

            return Http::response(gameFixture($killed >= 5
                ? 'quest/mob_talk_kill_complete.html'
                : 'quest/mob_talk_kill_incomplete.html'));
        }

        if (str_contains($url, 'mob.php')) {
            return Http::response('<div><a href="mob_talk.php?id=59293&stepid=3378&userspawn=&questid=742">Street Crawler</a></div>');
        }

        if (str_contains($url, 'somethingelse.php')) {
            if ($attacksRefused) {
                return Http::response('');
            }

            $killed++;

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/900/']);
        }

        if (str_contains($url, 'attack/900')) {
            return Http::response('var battle_result = "Hero has gained 500 experience!"; var defender_name = "Street Crawler";');
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            return Http::response($roomBlob($position));
        }

        return Http::response('<html>world</html>');
    });

    // Aliased so the setter can take the same parameter names as the fake's
    // own options ($setWorld(liveMobs: 99)) without shadowing them.
    $ragePool = &$rage;
    $livePool = &$liveMobs;

    return function (?int $rage = null, ?int $liveMobs = null) use (&$ragePool, &$livePool): void {
        if ($rage !== null) {
            $ragePool = $rage;
        }

        if ($liveMobs !== null) {
            $livePool = $liveMobs;
        }
    };
}

/**
 * DB side of the fake collect-quest world: room 1 (Rune Master, quest-giver)
 * –E– room 2 (Holy Elemental Keeper, the collect-objective source mob).
 * Rooms may already exist from seedQuestWorld().
 */
function seedCollectQuestWorld(): void
{
    Room::firstOrCreate(['id' => 1], Room::factory()->raw(['east' => 2]));
    Room::firstOrCreate(['id' => 2], Room::factory()->raw(['west' => 1]));
    Mob::factory()->create(['name' => 'Rune Master'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Holy Elemental Keeper'])->rooms()->attach(2, ['last_seen_at' => now()]);
}

/**
 * A mob_talk collect-step page mirroring the live markup: the objective reads
 * "{Item}: n/m" (no "killed" suffix) and the finish link appears only once
 * the item has been collected.
 */
function collectStepHtml(int $collected, string $item = 'Holy Elemental Crystal'): string
{
    $state = $collected >= 1 ? 'complete' : 'incomplete';
    $finish = $collected >= 1
        ? '<a href="mob_talk.php?id=60001&stepid=4001&userspawn=&finish=1" class="btn">Complete Task</a>'
        : '';

    return <<<HTML
        <div class="mob-dialog-container">
          <h2 class="mob-name">Rune Master</h2>
          <span class="badge">Primal Elemental Rune</span>
          <p class="mob-description">Bring me a {$item}.</p>
          <div class="quest-objective {$state}">
            <strong>{$item}:</strong> {$collected}/1
          </div>
          {$finish}
          <a href="mob.php?id=60001&h=npchash&userspawn=" class="btn">Go Back</a>
        </div>
        HTML;
}

/**
 * Stateful fake of a collect quest (quest 1449 step 4001, "Holy Elemental
 * Crystal: 0/1"): each Keeper kill drops the crystal; the step completes
 * after one drop and the finish href serves the captured reward page.
 * Includes the quest-helper: the tracker offers a "find my target" toggle for
 * the crystal, and while it is on, room blobs carry the compass (room 1
 * points east; room 2 is the designated target room).
 *
 * $liveKills caps how many Keepers can be killed before the room renders a
 * corpse — the source mobs running dry before the item drops. $dropChance of
 * 0 makes kills yield no crystal, so the pool can be exhausted without the
 * objective completing. The returned setter raises the pool (a respawn).
 */
function fakeCollectQuestWorld(
    int $rage = 50000,
    bool $helper = true,
    int $liveKills = PHP_INT_MAX,
    bool $drops = true,
    string $objectiveItem = 'Holy Elemental Crystal',
): Closure {
    $position = 1;
    $dropped = 0;
    $kills = 0;
    $helpOn = false;
    $finished = false;

    $roomBlob = function (int $roomId) use (&$helpOn, &$kills, &$liveKills): string {
        $mobs = match ($roomId) {
            1 => [questMobJson('Rune Master', 60001, 910, 'npchash', level: 80)],
            2 => [questMobJson('Holy Elemental Keeper', 60002, 920, 'y', level: 60, isDead: $kills >= $liveKills)],
            default => [],
        };

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0', 'east' => $roomId === 1 ? '2' : '0', 'south' => '0', 'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs, 'doorsData' => null,
            'questHelpData' => $helpOn ? ($roomId === 1 ? 'dpadcenter_east.jpg' : null) : null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$dropped, &$kills, &$helpOn, &$finished, $roomBlob, $rage, $helper, $drops, $objectiveItem) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => number_format($rage), 'level' => '80', 'width' => 0]));
        }

        if (str_contains($url, 'world_questHelper.php')) {
            // The quest leaves the tracker once turned in; until then it
            // reports the item count, and adds the "go and hand it over" row
            // the moment the count is met.
            $collected = min($dropped, 1);
            $rows = [[
                'type' => 'collect',
                'target' => $objectiveItem,
                'current' => $collected,
                'required' => 1,
                'conditionId' => 555,
            ]];

            if ($collected >= 1) {
                $rows[] = ['type' => 'talk', 'verb' => 'Return to', 'target' => 'Rune Master', 'mobId' => 910];
            }

            return Http::response(questHelperJson(! $helper || $finished ? [] : [[
                'questId' => 1449,
                'name' => 'Primal Elemental Rune',
                'stepId' => 4001,
                'rows' => $rows,
            ]]));
        }

        if (str_contains($url, 'quest_help.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $helpOn = ($query['state'] ?? '0') === '1';

            return Http::response(json_encode(['questHelpOn' => $helpOn ? 1 : 0, 'stepId' => 4001, 'conditionId' => 555]));
        }

        if (str_contains($url, 'mob_talk.php')) {
            if (str_contains($url, 'finish=1')) {
                $finished = true;

                return Http::response(gameFixture('quest/mob_talk_step_finish.html'));
            }

            return Http::response(collectStepHtml(min($dropped, 1), $objectiveItem));
        }

        if (str_contains($url, 'mob.php')) {
            return Http::response('<div><a href="mob_talk.php?id=60001&stepid=4001&userspawn=&questid=1449">Primal Elemental Rune</a></div>');
        }

        if (str_contains($url, 'somethingelse.php')) {
            $kills++;

            if ($drops) {
                $dropped++;
            }

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/901/']);
        }

        if (str_contains($url, 'attack/901')) {
            return Http::response(
                'var battle_result = "Hero has gained 500 experience!"; var defender_name = "Holy Elemental Keeper";'
                .($drops ? '<div id="found_items"><b>WIN: Found Holy Elemental Crystal</b></div>' : '')
            );
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            return Http::response($roomBlob($position));
        }

        return Http::response('<html>world</html>');
    });

    return function (int $newLiveKills) use (&$liveKills): void {
        $liveKills = $newLiveKills;
    };
}

/**
 * Fake PvP world: search returns the captured results; every attack 302s to a
 * plrattack win; userstats reports a configurable rage pool.
 */
function fakePvpWorld(int $rage = 50000): void
{
    Http::fake(function ($request) use ($rage) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => number_format($rage), 'level' => '80', 'width' => 0]));
        }

        if (str_contains($url, 'attacklog')) {
            // No prior attacks: nothing on cooldown.
            return Http::response('<html><table></table></html>');
        }

        if (str_contains($url, 'playersearch.php')) {
            return Http::response(gameFixture('playersearch_results.html'));
        }

        if (str_contains($url, 'somethingelse.php')) {
            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/plrattack/700/']);
        }

        if (str_contains($url, 'plrattack/700')) {
            return Http::response('var battle_result = "OFFENSIVE has gained 25 experience!"; var defender_name = "OFFENSIVE";');
        }

        return Http::response('<html>world</html>');
    });
}
