<?php

use App\Game\Engine\MobRunConfig;

it('round-trips drop_junk through the config array', function () {
    $config = MobRunConfig::fromArray(['mob_names' => ['Kix Harvester'], 'drop_junk' => true]);

    expect($config->dropJunk)->toBeTrue()
        ->and(MobRunConfig::fromArray($config->toArray())->dropJunk)->toBeTrue();
});

it('defaults drop_junk to off', function () {
    expect(MobRunConfig::fromArray(['mob_names' => []])->dropJunk)->toBeFalse();
});

it('round-trips the pass options and defaults them to a single unpaced run', function () {
    $legacy = MobRunConfig::fromArray(['mob_names' => ['Kix Harvester']]);

    expect($legacy->runCount)->toBe(0)
        ->and($legacy->attackIntervalSeconds)->toBeNull();

    $config = MobRunConfig::fromArray([
        'mob_names' => ['Kix Harvester'],
        'run_count' => 3,
        'attack_interval_seconds' => 300,
    ]);
    $rebuilt = MobRunConfig::fromArray($config->toArray());

    expect($rebuilt->runCount)->toBe(3)
        ->and($rebuilt->attackIntervalSeconds)->toBe(300);
});
