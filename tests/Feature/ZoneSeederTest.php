<?php

use App\Models\Area;
use App\Models\Zone;
use Database\Seeders\AreaSeeder;
use Database\Seeders\ZoneSeeder;

it('seeds zones and stamps every area', function () {
    (new AreaSeeder)->run();
    (new ZoneSeeder)->run();

    expect(Zone::count())->toBe(31)
        ->and(Zone::find(0)->name)->toBe('Deleted')
        ->and(Area::whereNull('zone_id')->count())->toBe(0)
        ->and(Area::where('name', 'Arcane Dimension')->first()->zone->name)->toBe('Dimensions');
});

it('is idempotent', function () {
    (new AreaSeeder)->run();
    (new ZoneSeeder)->run();
    (new ZoneSeeder)->run();

    expect(Zone::count())->toBe(31);
});
