<?php

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestRunConfig;

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

it('defaults smart mode to off in every run config', function () {
    expect(MobRunConfig::fromArray(['mob_names' => []])->smart)->toBeFalse()
        ->and(QuestRunConfig::fromArray([])->smart)->toBeFalse()
        ->and(QuestListRunConfig::fromArray([])->smart)->toBeFalse();
});

it('round-trips smart mode through every run config array', function () {
    $mob = MobRunConfig::fromArray(['mob_names' => ['Kix Harvester'], 'smart' => true]);
    $quest = QuestRunConfig::fromArray(['npc_name' => 'Stella', 'quest_id' => 742, 'smart' => true]);
    $list = QuestListRunConfig::fromArray(['quest_list_id' => 1, 'smart' => true]);

    expect(MobRunConfig::fromArray($mob->toArray())->smart)->toBeTrue()
        ->and(QuestRunConfig::fromArray($quest->toArray())->smart)->toBeTrue()
        ->and(QuestListRunConfig::fromArray($list->toArray())->smart)->toBeTrue();
});
