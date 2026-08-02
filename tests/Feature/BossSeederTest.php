<?php

use App\Models\Boss;
use Database\Seeders\BossSeeder;

it('seeds the world-boss catalog', function () {
    (new BossSeeder)->run();

    expect(Boss::count())->toBe(7);

    $boss = Boss::find(127);
    expect($boss->name)->toBe('Cosmos, Great All Being')
        ->and($boss->nick)->toBe('Cosmos')
        ->and($boss->rage_to_join)->toBe(1500);
});

it('is idempotent', function () {
    (new BossSeeder)->run();
    (new BossSeeder)->run();

    expect(Boss::count())->toBe(7);
});
