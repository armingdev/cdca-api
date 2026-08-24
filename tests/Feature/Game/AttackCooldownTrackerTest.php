<?php

use App\Game\Combat\AttackCooldownTracker;
use App\Game\Data\AttackRefusal;
use App\Game\Data\AttackTarget;
use App\Game\Enums\AttackRefusalReason;
use App\Game\Enums\TargetAttackability;
use App\Models\AttackCooldown;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function trackerFor(Character $character): AttackCooldownTracker
{
    return AttackCooldownTracker::forCharacter($character);
}

it('seeds cooldowns from the game attack log, which survives our restarts', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    // The log's newest entry is 8/22/2026 3:50am game time = 08:50 UTC.
    $this->travelTo('2026-08-22 09:00:00');

    Http::fake(['*attacklog*' => Http::response(gameFixture('attacklog_out.html'))]);

    $blocking = trackerFor($character)->syncFromAttackLog();

    expect($blocking)->toBe(3)
        ->and(AttackCooldown::where('source', 'attack-log')->count())->toBe(3);

    $azraid5 = AttackCooldown::where('opponent_player_id', 105387)->first();

    expect($azraid5->minutesRemaining())->toBe(50);
});

it('ignores log entries whose window has already elapsed', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    // Two hours after the newest logged attack — every entry is free again.
    $this->travelTo('2026-08-22 11:00:00');

    Http::fake(['*attacklog*' => Http::response(gameFixture('attacklog_out.html'))]);

    expect(trackerFor($character)->syncFromAttackLog())->toBe(0)
        ->and(AttackCooldown::count())->toBe(0);
});

it('filters a target list down to what can actually be attacked now', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    AttackCooldown::record($character->id, 265, 'Krongstein');

    $targets = [
        new AttackTarget(playerId: 265, name: 'Krongstein'),
        new AttackTarget(playerId: 158701, name: 'StarPower'),
    ];

    $attackable = trackerFor($character)->attackable($targets);

    expect($attackable)->toHaveCount(1)
        ->and($attackable[0]->playerId)->toBe(158701);
});

it('skips targets the game has already marked out of our level band', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $targets = [
        new AttackTarget(playerId: 1, name: 'Weakling', attackability: TargetAttackability::TooWeak),
        new AttackTarget(playerId: 2, name: 'Titan', attackability: TargetAttackability::TooPowerful),
        new AttackTarget(playerId: 3, name: 'Fair fight', attackability: TargetAttackability::InRange),
    ];

    $attackable = trackerFor($character)->attackable($targets);

    expect($attackable)->toHaveCount(1)
        ->and($attackable[0]->name)->toBe('Fair fight');
});

it('checks the whole list in one pass rather than one query per target', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $countQueriesFor = function (int $size) use ($character): int {
        $targets = array_map(
            fn (int $id): AttackTarget => new AttackTarget(playerId: $id, name: "Player {$id}"),
            range(1, $size),
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        trackerFor($character)->attackable($targets);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // Warm the connection so the first call's one-off setup query does not
    // land in the measurement.
    $countQueriesFor(1);

    // A crew hitlist returns 404 targets in one page; the check must not
    // scale with that.
    expect($countQueriesFor(50))->toBe(1)
        ->and($countQueriesFor(5))->toBe(1);
});

it('corrects the record from a refusal using the elapsed minutes the game states', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $target = new AttackTarget(playerId: 105387, name: 'azraid5');

    // We believed the target was free; the game says we hit it 3 minutes ago.
    $refusal = new AttackRefusal(AttackRefusalReason::Cooldown, 'refused', minutesSinceLastAttack: 3);

    $cooldown = trackerFor($character)->recordRefusal($target, $refusal);

    expect($cooldown->minutesRemaining())->toBe(57)
        ->and($cooldown->source)->toBe('refusal');
});

it('does not invent a cooldown from a refusal it could not classify', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $refusal = new AttackRefusal(AttackRefusalReason::Unknown, 'something new');

    expect(trackerFor($character)->recordRefusal(new AttackTarget(playerId: 1, name: 'x'), $refusal))->toBeNull()
        ->and(AttackCooldown::count())->toBe(0);
});

it('reports when the earliest blocked target frees up', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    AttackCooldown::record($character->id, 1, 'later', now()->subMinutes(10));
    AttackCooldown::record($character->id, 2, 'sooner', now()->subMinutes(45));

    expect(trackerFor($character)->nextFreeInMinutes())->toBe(15);
});

it('reports no wait when nothing is on cooldown', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    expect(trackerFor($character)->nextFreeInMinutes())->toBeNull();
});
