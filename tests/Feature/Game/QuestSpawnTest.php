<?php

use App\Game\Engine\QuestRunConfig;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Rga;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Quest 742 ("kill 5 Street Crawlers" for Stella in room 1), except the
 * Street Crawler is a spawn: room 2 holds Sewer Rats, and every
 * $ratsPerSpawn rat kills draw one Street Crawler out (type 3). The step
 * completes on Street Crawler kills only.
 *
 * @return Closure(): array{rats: int, crawlers: int}
 */
function fakeSpawnQuestWorld(int $ratsPerSpawn): Closure
{
    $position = 1;
    $rats = 0;
    $sinceSpawn = 0;
    $crawlers = 0;
    $crawler = 'absent';

    Http::fake(function (Request $request) use (&$position, &$rats, &$sinceSpawn, &$crawlers, &$crawler, $ratsPerSpawn) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response('{"exp":"1,000","rage":"50,000","level":"20","width":0}');
        }

        if (str_contains($url, 'world_questHelper.php')) {
            return Http::response(questHelperJson([]));
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response(fakeSkillsPageHtml());
        }

        if (str_contains($url, 'mob_talk.php')) {
            if (str_contains($url, 'finish=1')) {
                return Http::response(gameFixture('quest/mob_talk_step_finish.html'));
            }

            return Http::response(gameFixture($crawlers >= 5 ? 'quest/mob_talk_kill_complete.html' : 'quest/mob_talk_kill_incomplete.html'));
        }

        if (str_contains($url, 'mob.php')) {
            return Http::response('<div><a href="mob_talk.php?id=59293&stepid=3378&userspawn=&questid=742">Street Crawler</a></div>');
        }

        if (str_contains($url, 'somethingelse.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_starts_with((string) $query['attackid'], 'RAT')) {
                $rats++;

                if ($crawler !== 'alive' && ++$sinceSpawn >= $ratsPerSpawn) {
                    $crawler = 'alive';
                    $sinceSpawn = 0;
                }
            } else {
                $crawlers++;
                $crawler = 'dead';
            }

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/900/']);
        }

        if (str_contains($url, 'attack/900')) {
            return Http::response('var successful = 1; var battle_result = "Hero has gained 500 experience!";');
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $position = (int) $query['room'] ?: $position;

            $mobs = $position === 1
                ? [questMobJson('Stella', 59293, 888, 'npchash', level: 10)]
                : [
                    ['name' => 'Sewer Rat', 'level' => '18', 'rage' => '20', 'h' => 'x', 'encid' => 'RAT1', 'mobId' => '11', 'spawnId' => '1', 'type' => 2],
                    ['name' => 'Sewer Rat', 'level' => '18', 'rage' => '20', 'h' => 'x', 'encid' => 'RAT2', 'mobId' => '11', 'spawnId' => '2', 'type' => 2],
                    ...($crawler === 'absent' ? [] : [[
                        'name' => 'Street Crawler', 'level' => '20', 'rage' => '100', 'h' => 'x', 'encid' => 'CRAWLER',
                        'mobId' => '4000', 'spawnId' => '5000', 'spawnedBy' => 'Hero', 'isDead' => $crawler === 'dead', 'type' => 3,
                    ]]),
                ];

            return Http::response(json_encode([
                'error' => '', 'curRoom' => (string) $position, 'name' => "Room {$position}",
                'north' => '0', 'east' => $position === 1 ? '2' : '0', 'south' => '0', 'west' => $position === 2 ? '1' : '0',
                'roomDetailsNew' => $mobs, 'doorsData' => null,
            ]));
        }

        return Http::response('<html>world</html>');
    });

    return function () use (&$rats, &$crawlers): array {
        return ['rats' => $rats, 'crawlers' => $crawlers];
    };
}

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    seedQuestWorld();
    Mob::where('name', 'Street Crawler')->update(['spawnable' => true]);
});

it('draws out a spawnable kill target as often as the quest needs, then turns the quest in', function () {
    $kills = fakeSpawnQuestWorld(ratsPerSpawn: 2);
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(npcName: 'Stella', questId: 742))->run();

    expect($summary->completed)->toBeTrue()
        ->and($summary->stopReason)->toBe('Quest complete.')
        // Only the quest's own target counts as its kills.
        ->and($summary->kills)->toBe(5)
        ->and($kills())->toBe(['rats' => 10, 'crawlers' => 5]);
});
