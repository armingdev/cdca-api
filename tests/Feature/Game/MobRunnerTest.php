<?php

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\RunEndReason;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

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
