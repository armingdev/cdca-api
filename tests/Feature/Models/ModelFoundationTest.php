<?php

use App\Models\Character;
use App\Models\Mob;
use App\Models\Rga;
use App\Models\Room;
use App\Models\WorldChange;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('encrypts rga credentials and cookies at rest', function () {
    $rga = Rga::factory()->withSession()->create([
        'password' => 'super-secret',
    ]);

    $raw = DB::table('rgas')->where('id', $rga->id)->first();

    expect($raw->password)->not->toContain('super-secret')
        ->and($raw->cookies)->not->toContain('rg_sess_id')
        ->and($rga->fresh()->password)->toBe('super-secret')
        ->and($rga->fresh()->cookies)->toHaveKeys(['rg_sess_id', 'token', 'cuserid2', 'owip']);
});

it('links characters to their rga and enforces per-server suid uniqueness', function () {
    $rga = Rga::factory()->create();
    $character = Character::factory()->for($rga)->create(['suid' => 2403, 'server_id' => 1]);

    expect($character->rga->is($rga))->toBeTrue()
        ->and($rga->characters()->count())->toBe(1);

    // Same suid on the other server is fine.
    Character::factory()->for($rga)->torax()->create(['suid' => 2403]);

    expect(fn () => Character::factory()->for($rga)->create(['suid' => 2403, 'server_id' => 1]))
        ->toThrow(QueryException::class);
});

it('stores rooms under their game id and exposes exits', function () {
    $room = Room::factory()->create([
        'id' => 31954,
        'north' => 31955,
        'west' => 31953,
    ]);

    expect($room->id)->toBe(31954)
        ->and($room->exits())->toBe(['north' => 31955, 'west' => 31953]);
});

it('attaches mobs to rooms through the pivot with a sighting timestamp', function () {
    $room = Room::factory()->create();
    $mob = Mob::factory()->create();

    $mob->rooms()->attach($room->id, ['last_seen_at' => now()]);

    expect($mob->rooms()->first()->is($room))->toBeTrue()
        ->and($room->mobs()->first()->pivot->last_seen_at)->not->toBeNull();
});

it('journals world changes without eloquent timestamps', function () {
    $room = Room::factory()->create();

    $change = WorldChange::create([
        'room_id' => $room->id,
        'field' => 'north',
        'old_value' => null,
        'new_value' => '23332',
        'observed_at' => now(),
    ]);

    expect($change->room->is($room))->toBeTrue()
        ->and($change->observed_at)->not->toBeNull();
});
