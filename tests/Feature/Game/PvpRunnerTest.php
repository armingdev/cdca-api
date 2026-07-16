<?php

use App\Game\Combat\PvpRunner;
use App\Game\Engine\PvpRunConfig;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Rga;

// The fake PvP world (fakePvpWorld) lives in tests/Pest.php.
beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('attacks each target the configured number of times', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakePvpWorld();

    $summary = PvpRunner::forCharacter($character, new PvpRunConfig(
        targets: ['OFFENSIVE', 'offensive2'],
        attacksPerTarget: 2,
    ))->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeTrue()
        ->and($summary->attacks)->toBe(4)
        ->and(BattleEvent::where('kind', 'pvp')->count())->toBe(4)
        ->and($summary->stopReason)->toBe('PvP run complete.');
});

it('stops at the rage floor before attacking', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakePvpWorld(rage: 1000);

    $summary = PvpRunner::forCharacter($character, new PvpRunConfig(
        targets: ['OFFENSIVE'],
        stopRage: 2500,
    ))->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeFalse()
        ->and($summary->attacks)->toBe(0)
        ->and($summary->stopReason)->toContain('below the 2500 floor');
});

it('drives PvP through the outwar:pvp command', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakePvpWorld();

    $this->artisan('outwar:pvp', ['character' => $character->id, '--target' => ['OFFENSIVE']])
        ->assertSuccessful()
        ->expectsOutputToContain('1 attack(s)');
});
