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
