<?php

use App\Models\Mob;
use Database\Seeders\MobSeeder;
use Database\Seeders\RoomSeeder;
use Illuminate\Support\Facades\DB;

it('seeds the mob catalog with normalized flags and placements', function () {
    (new RoomSeeder)->run();
    (new MobSeeder)->run();

    // 4,268 seed rows, duplicate names merged → 4,256 mobs.
    expect(Mob::count())->toBe(4256);

    // String-serialized seed record: Id "3873", flags "true"/"false".
    $mob = Mob::where('name', 'Holy Crusader')->first();
    expect($mob->game_mob_id)->toBe(3873)
        ->and($mob->level)->toBe(80)
        ->and($mob->attackable)->toBeTrue()
        ->and($mob->talkable)->toBeFalse();

    expect(Mob::where('name', 'Damned Trade')->first()->talkable)->toBeTrue();

    // Raid flag folds into type 1; seed Id 0 yields no game_mob_id.
    $raid = Mob::where('name', 'Skarthul the Avenged')->first();
    expect($raid->type)->toBe(1)
        ->and($raid->game_mob_id)->toBeNull();

    // Duplicate seed names collapse into one mob.
    expect(Mob::where('name', 'Halloween Trader')->count())->toBe(1);

    // 133,900 unique merged placements, 16 pointing at rooms absent from the
    // seeded graph → 133,884 pivot rows.
    expect(DB::table('mob_room')->count())->toBe(133884);
});

it('never overwrites live recorder data on existing mobs', function () {
    Mob::factory()->create([
        'name' => 'Holy Crusader',
        'game_mob_id' => 12345,
        'level' => 5,
        'type' => 0,
    ]);

    (new RoomSeeder)->run();
    (new MobSeeder)->run();

    $mob = Mob::where('name', 'Holy Crusader')->first();
    expect($mob->game_mob_id)->toBe(12345)
        ->and($mob->level)->toBe(5)
        ->and($mob->attackable)->toBeTrue()
        ->and($mob->spawn_count)->toBe(369);
});

it('is idempotent', function () {
    (new RoomSeeder)->run();
    (new MobSeeder)->run();
    (new MobSeeder)->run();

    expect(Mob::count())->toBe(4256)
        ->and(DB::table('mob_room')->count())->toBe(133884);
});
