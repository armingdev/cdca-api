<?php

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\RunEndReason;
use App\Game\Exceptions\GatedRoomException;
use App\Game\World\GatedRooms;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
});

/**
 * A mob somebody else killed between our room load and the attack answers
 * "Invalid mob!" as an HTTP 500. With dozens of our own characters farming one
 * spawn that is plain contention — six lost races in a row used to trip the
 * five-refusal cap, drop a quest's only target and skip the quest.
 */
it('keeps attacking a mob that other players keep beating it to', function () {
    $attempts = 0;
    fakeCombatWorld(attack: function () use (&$attempts) {
        return ++$attempts <= 6
            ? Http::response(gameFixture('attack_invalid_mob_500.html'), 500)
            : Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/555/']);
    });
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester'], maxKills: 1))->run();

    expect($summary->endReason)->toBe(RunEndReason::TargetReached)
        ->and($summary->wins)->toBe(1)
        ->and($summary->errors)->toBe(6);
});

it('drops a mob whose attacks keep failing once its own streak cap is reached', function (string $body, int $status, int $attacks) {
    fakeCombatWorld(attack: fn () => Http::response($body, $status));
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester'], maxKills: 1))->run();

    expect($summary->endReason)->toBe(RunEndReason::Stuck)
        ->and($summary->wins)->toBe(0)
        ->and($summary->errors)->toBe($attacks)
        ->and($summary->stopReason)->toContain('Kix Harvester');
})->with([
    'refused with an empty 200' => ['', 200, 5],
    'already gone every time' => [gameFixture('attack_invalid_mob_500.html'), 500, 10],
]);

it('keeps off a room the game has turned the character away from', function () {
    fakeCombatWorld();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    app(GatedRooms::class)->remember($character, new GatedRoomException(2, 'To enter this room you must be carrying Harvest Pass.'));

    $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester'], maxKills: 1))->run();

    expect($summary->wins)->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ajax_changeroomb.php') && $request['room'] === '2');
});

/**
 * 100+ of our characters on one list used to pick the same spawn; one killed
 * it and the rest got "Invalid mob!". A spawn is claimed for the length of one
 * attack, keyed by its spawnId (Kix Harvester's is 1234, in room 2).
 */
describe('mobs our other characters are fighting', function () {
    it('holds a claim on the mob while attacking it and lets go after', function () {
        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        $claimKey = "mob-claim:{$character->server_id}:2:1234";
        $claimedDuringAttack = null;
        fakeCombatWorld(attack: function () use ($claimKey, &$claimedDuringAttack) {
            $probe = Cache::lock($claimKey, 15);
            $claimedDuringAttack = ! $probe->get();
            $probe->release();

            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/555/']);
        });

        $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester'], maxKills: 1))->run();

        expect($summary->wins)->toBe(1)
            ->and($claimedDuringAttack)->toBeTrue()
            ->and(Cache::lock($claimKey, 15)->get())->toBeTrue();
    });

    it('waits for another of our characters to finish a mob, then takes its turn', function () {
        Sleep::fake(syncWithCarbon: true);
        fakeCombatWorld();
        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        Cache::lock("mob-claim:{$character->server_id}:2:1234", 5)->get();

        $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester'], maxKills: 1))->run();

        expect($summary->wins)->toBe(1);
        Sleep::assertSleptTimes(2);
    });

    it('leaves a room whose mobs our other characters keep claimed', function () {
        Sleep::fake(syncWithCarbon: true);
        fakeCombatWorld();
        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        Cache::lock("mob-claim:{$character->server_id}:2:1234", 600)->get();

        $summary = MobRunner::forCharacter($character, new MobRunConfig(mobNames: ['Kix Harvester'], maxKills: 1))->run();

        expect($summary->wins)->toBe(0);
        Sleep::assertSleptTimes(5);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'somethingelse.php'));
    });
});
