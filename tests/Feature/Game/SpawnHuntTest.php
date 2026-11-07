<?php

use App\Game\Auth\LoginService;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Room 2 as captured in Bunker Hill (2026-11-07): ordinary A Rocks, and a
 * Possible Pyrite that only exists once $killsToSpawn rocks have died since
 * the last one. A killed Pyrite stays listed (type 3, isDead) until the next
 * spawn. Battle pages are the real captures.
 *
 * @param  list<array<string, mixed>>  $extraMobs  more roomDetailsNew entries
 * @return Closure(): list<string> the encids attacked so far
 */
function fakeSpawnWorld(int $killsToSpawn, array $extraMobs = [], bool $rocks = true): Closure
{
    $attacked = [];
    $sinceSpawn = 0;
    $pyrite = 'absent';

    Http::fake(function (Request $request) use (&$attacked, &$sinceSpawn, &$pyrite, $killsToSpawn, $extraMobs, $rocks) {
        $url = $request->url();

        if (str_contains($url, 'userstats.php')) {
            return Http::response('{"exp":"1","rage":"50000","level":"95","width":100}');
        }

        if (str_contains($url, 'somethingelse.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $attacked[] = $encid = (string) $query['attackid'];

            if (str_starts_with($encid, 'ROCK')) {
                $sinceSpawn++;
                $spawned = $pyrite !== 'alive' && $sinceSpawn >= $killsToSpawn;

                if ($spawned) {
                    $pyrite = 'alive';
                    $sinceSpawn = 0;
                }

                return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/'.($spawned ? 2 : 1).'/']);
            }

            if ($encid === 'PYRITE') {
                $pyrite = 'dead';

                return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/1/']);
            }

            // Anything else (an NPC, a raid boss): the game's refusal.
            return Http::response('');
        }

        if (str_contains($url, 'attack/2/')) {
            return Http::response(gameFixture('spawn/attack_pve_spawn_line.html'));
        }

        if (str_contains($url, 'attack/1/')) {
            return Http::response(gameFixture('spawn/attack_pve_win.html'));
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            $mobs = $rocks ? [
                ['name' => 'A Rock', 'level' => '65', 'rage' => '39', 'h' => 'x', 'encid' => 'ROCK1', 'mobId' => '1492', 'spawnId' => '39307', 'type' => 2],
                ['name' => 'A Rock', 'level' => '65', 'rage' => '39', 'h' => 'x', 'encid' => 'ROCK2', 'mobId' => '1492', 'spawnId' => '39309', 'type' => 2],
            ] : [];

            if ($pyrite !== 'absent') {
                array_unshift($mobs, [
                    'name' => 'Possible Pyrite', 'level' => '66', 'rage' => '40', 'h' => 'x',
                    'encid' => 'PYRITE', 'mobId' => '1495', 'spawnId' => '39303',
                    'spawnedBy' => 'Hero', 'killedBy' => 'Hero', 'isDead' => $pyrite === 'dead', 'type' => 3,
                ]);
            }

            return Http::response(json_encode([
                'error' => '', 'name' => 'Bunker Hill', 'curRoom' => '2',
                'north' => '0', 'east' => '0', 'south' => '0', 'west' => '1',
                'roomDetailsNew' => [...$mobs, ...$extraMobs],
            ]));
        }

        return Http::response('<html>world page</html>');
    });

    return function () use (&$attacked): array {
        return $attacked;
    };
}

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    seedCombatWorld();
    Mob::factory()->create(['name' => 'Possible Pyrite', 'spawnable' => true])->rooms()->attach(2, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'A Rock'])->rooms()->attach(2, ['last_seen_at' => now()]);
    $this->character = Character::factory()->for(Rga::factory()->withSession())->create();
    $this->hunt = fn (array $config) => MobRunner::forCharacter($this->character, new MobRunConfig(...$config));
});

it('farms the room-mates until the spawn steps out, then kills it', function () {
    $attacked = fakeSpawnWorld(killsToSpawn: 3);
    $log = [];

    $summary = ($this->hunt)(['mobNames' => ['Possible Pyrite'], 'maxKills' => 1])
        ->run(log: function (string $line) use (&$log): void {
            $log[] = $line;
        });

    expect($attacked())->toBe(['ROCK1', 'ROCK1', 'ROCK1', 'PYRITE'])
        ->and($summary->endReason)->toBe(RunEndReason::TargetReached)
        ->and($summary->wins)->toBe(1)
        ->and($summary->triggerKills)->toBe(3)
        ->and($log)->toContain('Possible Pyrite needs to be spawned — farming its room-mates.')
        ->and($log)->toContain('Possible Pyrite steps out of the shadows!');
});

it('keeps drawing out the next one in a mob farm', function () {
    $attacked = fakeSpawnWorld(killsToSpawn: 2);

    $summary = ($this->hunt)(['mobNames' => ['Possible Pyrite'], 'maxKills' => 2])->run();

    expect($attacked())->toBe(['ROCK1', 'ROCK1', 'PYRITE', 'ROCK1', 'ROCK1', 'PYRITE'])
        ->and($summary->wins)->toBe(2)
        ->and($summary->triggerKills)->toBe(4);
});

it('gives up on a room that never delivers and parks for respawn instead of burning rage', function () {
    $attacked = fakeSpawnWorld(killsToSpawn: 1000);

    $summary = ($this->hunt)(['mobNames' => ['Possible Pyrite']])->run();

    expect($attacked())->toHaveCount(40)
        ->and($summary->wins)->toBe(0)
        ->and($summary->triggerKills)->toBe(40)
        ->and($summary->endReason)->toBe(RunEndReason::Completed)
        ->and($summary->targetsRespawnPending)->toBeTrue();
});

it('never farms talk-only NPCs or raid bosses for a spawn', function () {
    $attacked = fakeSpawnWorld(killsToSpawn: 1, rocks: false, extraMobs: [
        ['name' => 'Brutus', 'level' => '1', 'rage' => '30', 'h' => 'x', 'encid' => 'NPC', 'mobId' => '9', 'type' => 2, 'noAttack' => true],
        ['name' => 'Gregov, Knight of the Woods', 'level' => '120', 'rage' => '400', 'h' => 'x', 'encid' => 'RAID', 'mobId' => '4387', 'type' => 1, 'canForm' => true],
    ]);

    $summary = ($this->hunt)(['mobNames' => ['Possible Pyrite']])->run();

    expect($attacked())->toBe([])
        ->and($summary->targetsRespawnPending)->toBeTrue();
});

it('does not farm room-mates for a target that is not spawnable', function () {
    // Kix Harvester also lives in room 2 (seedCombatWorld) but is ordinary:
    // absent means "on its respawn timer", not "needs drawing out".
    $attacked = fakeSpawnWorld(killsToSpawn: 1);

    $summary = ($this->hunt)(['mobNames' => ['Kix Harvester']])->run();

    expect($attacked())->toBe([])
        ->and($summary->targetsRespawnPending)->toBeTrue();
});

it('counts only the spawned target toward a mob run\'s kills', function () {
    fakeSpawnWorld(killsToSpawn: 3);

    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'status' => RunStatus::Running,
            'config' => ['mob_names' => ['Possible Pyrite'], 'max_kills' => 1],
        ]))
        ->for($this->character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->progress['kills_done'])->toBe(1)
        // Every won fight still shows in the run's tally.
        ->and($participant->wins)->toBe(4);
});
