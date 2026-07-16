<?php

use App\Models\Mob;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
 * Stateful fake game for combat tests: two mapped rooms (1 –E– 2), a Kix
 * Harvester in room 2 that dies after one successful attack, configurable
 * rage. Pair with rooms/mob seeded via seedCombatWorld().
 */
function fakeCombatWorld(int $rage = 5000): void
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
