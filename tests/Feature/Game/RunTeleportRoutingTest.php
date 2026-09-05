<?php

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\QuestRunConfig;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\ParseException;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
use App\Models\CharacterTeleportAnchor;
use App\Models\Mob;
use App\Models\Rga;
use App\Models\Room;
use App\Models\TeleportAnchor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

/**
 * A world in two disconnected halves: the character starts in room 1, and the
 * only Kix Harvester lives in room 900, which no walk reaches. An anchor lands
 * in 900.
 */
function seedSplitWorld(): void
{
    Room::factory()->create(['id' => 1]);
    Room::factory()->create(['id' => 900]);
    Mob::factory()->create(['name' => 'Kix Harvester'])->rooms()->attach(900, ['last_seen_at' => now()]);
}

function grantAnchor(Character $character, int $roomId, string $name = 'Astral Ward', int $rage = 0): TeleportAnchor
{
    $anchor = TeleportAnchor::factory()->create([
        'name' => $name,
        'room_id' => $roomId,
        'rage_cost' => $rage,
        'cooldown_minutes' => $rage > 0 ? 60 : 0,
    ]);

    CharacterTeleportAnchor::create([
        'character_id' => $character->id,
        'teleport_anchor_id' => $anchor->id,
        'iid' => 424242,
        'is_available' => true,
    ]);

    return $anchor;
}

/**
 * Room blobs for the split world; room 900 holds one live mob until killed.
 */
function fakeSplitWorld(): void
{
    $killed = false;

    Http::fake(function ($request) use (&$killed) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response('{"exp":"1,000","rage":"5,000","level":"60","width":0}');
        }

        if (str_contains($url, 'backpack_action.php')) {
            return Http::response('{"status":"Astral Ward activated!<br>","redirectTo":"\/world"}');
        }

        if (str_contains($url, 'somethingelse.php')) {
            $killed = true;

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/555/']);
        }

        if (str_contains($url, 'attack/555')) {
            return Http::response(
                'var battle_result = "Hero has gained 950 experience!"; var attacker_result = "Win!";'
                .'var attacker_name = "Hero"; var defender_name = "Kix Harvester";'
            );
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            // The character is in room 1 until it teleports; afterwards the
            // game reports room 900 (the anchor's landing room).
            $inTarget = collect(Http::recorded())
                ->contains(fn ($pair) => str_contains($pair[0]->url(), 'backpack_action.php'));

            return Http::response(json_encode([
                'error' => '',
                'curRoom' => $inTarget ? '900' : '1',
                'name' => $inTarget ? 'Astral Rift' : 'Start',
                'north' => '0', 'east' => '0', 'south' => '0', 'west' => '0',
                'roomDetailsNew' => $inTarget && ! $killed ? [[
                    'name' => 'Kix Harvester', 'level' => '60', 'rage' => '150', 'h' => 'hash',
                    'encid' => 'FRESH', 'mobId' => '777', 'spawnId' => '1234',
                    'image' => 'mobs/kix.jpg', 'isDead' => false, 'type' => 0,
                    'lastKilledBy' => null, 'canForm' => false,
                ]] : [],
                'doorsData' => null,
            ]));
        }

        return Http::response('');
    });
}

it('teleports to a farm target no walk can reach', function () {
    seedSplitWorld();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    grantAnchor($character, 900);
    fakeSplitWorld();

    $lines = [];
    $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester']))
        ->run(log: function (string $line) use (&$lines) {
            $lines[] = $line;
        });

    expect($summary->wins)->toBe(1)
        ->and($lines)->toContain('Teleporting with Astral Ward (saves the walk).')
        ->and($lines)->toContain('1 teleport anchors available for routing.');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        && $request['action'] === 'activate');
});

it('never spends rage or a skill cooldown on run travel', function () {
    seedSplitWorld();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    // The only anchor reaching the target costs rage — a run must not take it.
    grantAnchor($character, 900, 'Teleport: Astral Rift', rage: 100);
    fakeSplitWorld();

    $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester']))->run();

    expect($summary->wins)->toBe(0)
        ->and($summary->stopReason)->toContain('No live targets');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        || str_contains($request->url(), 'cast_skills'));
});

it('walks as before when the character has no anchors', function () {
    seedCombatWorld();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    fakeCombatWorld();

    $lines = [];
    $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester']))
        ->run(log: function (string $line) use (&$lines) {
            $lines[] = $line;
        });

    expect($summary->wins)->toBe(1)
        ->and(implode(' ', $lines))->not->toContain('Teleporting');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'backpack_action.php'));
});

it('teleports to a quest-giver that no walk reaches', function () {
    // Stella lives in room 1; the character starts in the disconnected room 900.
    Room::factory()->create(['id' => 1]);
    Room::factory()->create(['id' => 900]);
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    grantAnchor($character, 1, 'Sanctum Key');

    $teleported = false;

    Http::fake(function ($request) use (&$teleported) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response('{"exp":"1,000","rage":"5,000","level":"20","width":0}');
        }

        if (str_contains($url, 'world_questHelper.php')) {
            // Quest 742 has not been started, so it is in no tracker — the
            // runner must fall back to walking (here, teleporting) to Stella.
            return Http::response(questHelperJson([]));
        }

        if (str_contains($url, 'backpack_action.php')) {
            $teleported = true;

            return Http::response('{"status":"Sanctum Key activated!<br>"}');
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            return Http::response(json_encode([
                'error' => '',
                'curRoom' => $teleported ? '1' : '900',
                'name' => $teleported ? 'Giver Room' : 'Far Away',
                'north' => '0', 'east' => '0', 'south' => '0', 'west' => '0',
                'roomDetailsNew' => $teleported ? [questMobJson('Stella', 59293, 888, 'npchash', level: 10)] : [],
                'doorsData' => null,
            ]));
        }

        // Everything past the giver (mob.php, mob_talk.php…) is out of scope
        // here: this test only proves the runner teleports to reach them.
        return Http::response('');
    });

    try {
        QuestRunner::forCharacter($character, new QuestRunConfig(npcName: 'Stella', questId: 742))->run();
    } catch (GameException|ParseException) {
        // The quest flow itself is covered by QuestRunnerTest — anything else
        // is a real failure and must not be swallowed.
    }

    expect($teleported)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        && $request['action'] === 'activate');
});
