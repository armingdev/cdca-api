<?php

use App\Game\Enums\RunMode;
use App\Jobs\RunBrawlJob;
use App\Jobs\RunJob;
use App\Jobs\RunMobJob;
use App\Jobs\RunPvpJob;
use App\Jobs\RunQuestJob;
use App\Jobs\RunQuestListJob;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\Room;
use App\Models\RunParticipant;
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
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

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

/**
 * Minimal valid skills_info.php page (Circumspect-shaped, not recharging) so
 * pre-run skill syncs in the fake worlds parse instead of throwing; cooldown
 * windows then derive from local last_cast_at bookkeeping.
 */
function fakeSkillInfoHtml(): string
{
    return '<div><h5>Circumspect - Level 1</h5>Reduces the rage cost of fighting.</div>'
        .'<b>Rage Cost:</b><br> 20 <b>Cooldown:</b><br> 720 mins <b>Duration:</b><br> 60 mins';
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
            return Http::response(fakeSkillInfoHtml());
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response('Status: You just cast a skill');
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
            return Http::response(fakeSkillInfoHtml());
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
            return Http::response(fakeSkillInfoHtml());
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

function questMobJson(string $name, int $mobId, int $spawnId, string $hash, int $level, bool $isDead = false): array
{
    return [
        'name' => $name, 'level' => (string) $level, 'rage' => '100', 'h' => $hash,
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
 * $liveMobs caps how many Street Crawlers can be killed before the room
 * renders a corpse instead — the world running dry mid-objective. Raising it
 * through the returned setter is a respawn.
 */
function fakeQuestWorld(int $rage = 50000, int $level = 20, int $levelUps = 0, int $liveMobs = PHP_INT_MAX): Closure
{
    $position = 1;
    $killed = 0;

    $roomBlob = function (int $roomId) use (&$killed, &$liveMobs): string {
        $mobs = match ($roomId) {
            1 => [questMobJson('Stella', 59293, 888, 'npchash', level: 10)],
            2 => [questMobJson('Street Crawler', 4000, 5000, 'x', level: 20, isDead: $killed >= $liveMobs)],
            default => [],
        };

        return json_encode([
            'error' => '', 'curRoom' => (string) $roomId, 'name' => "Room {$roomId}",
            'north' => '0', 'east' => $roomId === 1 ? '2' : '0', 'south' => '0', 'west' => $roomId === 2 ? '1' : '0',
            'roomDetailsNew' => $mobs, 'doorsData' => null,
        ]);
    };

    Http::fake(function ($request) use (&$position, &$killed, &$level, &$levelUps, $roomBlob, &$rage) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => number_format($rage), 'level' => (string) $level, 'width' => 0]));
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
            return Http::response(fakeSkillInfoHtml());
        }

        if (str_contains($url, 'cast_skills.php') && $request->method() === 'POST') {
            return Http::response('Status: You just cast a skill');
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
function collectStepHtml(int $collected): string
{
    $state = $collected >= 1 ? 'complete' : 'incomplete';
    $finish = $collected >= 1
        ? '<a href="mob_talk.php?id=60001&stepid=4001&userspawn=&finish=1" class="btn">Complete Task</a>'
        : '';

    return <<<HTML
        <div class="mob-dialog-container">
          <h2 class="mob-name">Rune Master</h2>
          <span class="badge">Primal Elemental Rune</span>
          <p class="mob-description">Bring me a Holy Elemental Crystal.</p>
          <div class="quest-objective {$state}">
            <strong>Holy Elemental Crystal:</strong> {$collected}/1
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
function fakeCollectQuestWorld(int $rage = 50000, bool $helper = true, int $liveKills = PHP_INT_MAX, bool $drops = true): Closure
{
    $position = 1;
    $dropped = 0;
    $kills = 0;
    $helpOn = false;

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

    Http::fake(function ($request) use (&$position, &$dropped, &$kills, &$helpOn, $roomBlob, $rage, $helper, $drops) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => number_format($rage), 'level' => '80', 'width' => 0]));
        }

        if (str_contains($url, 'world_questHelper.php')) {
            return Http::response(json_encode([
                'qtable' => $helper
                    ? '<a href="javascript:void(0);" onClick="getQuestHelpData2(\'1449\', \'0\', \'Holy Elemental Crystal\', \'4001\', \'555\');">Holy Elemental Crystal</a>: 0/1'
                    : '',
            ]));
        }

        if (str_contains($url, 'quest_help.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $helpOn = ($query['state'] ?? '0') === '1';

            return Http::response(json_encode(['questHelpOn' => $helpOn ? 1 : 0, 'stepId' => 4001, 'conditionId' => 555]));
        }

        if (str_contains($url, 'mob_talk.php')) {
            if (str_contains($url, 'finish=1')) {
                return Http::response(gameFixture('quest/mob_talk_step_finish.html'));
            }

            return Http::response(collectStepHtml(min($dropped, 1)));
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
