<?php

use App\Game\Combat\StatsService;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('refreshes rage, exp and level from userstats.php', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create(['rage' => 0, 'level' => 1]);

    Http::fake(['*userstats.php*' => Http::response(gameFixture('userstats_response.json'))]);

    $stats = StatsService::forCharacter($character)->refresh();

    expect($stats->rage)->toBe(2000)
        ->and($character->fresh()->rage)->toBe(2000)
        ->and($character->fresh()->exp)->toBe(80906)
        ->and($character->fresh()->level)->toBe(19)
        ->and($character->fresh()->last_stats_at)->not->toBeNull();
});

it('levels up when eligible and refreshes stats', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create(['level' => 19]);

    Http::fake([
        '*levelup.php*' => Http::response('You are now Level 20! Your RAGE (energy) has been automatically refilled!'),
        '*userstats.php*' => Http::response('{"exp":"90,000","rage":"25,000","level":"20","width":-300}'),
    ]);

    $leveled = StatsService::forCharacter($character)->tryLevelUp();

    expect($leveled)->toBeTrue()
        ->and($character->fresh()->level)->toBe(20)
        ->and($character->fresh()->rage)->toBe(25000);
});

it('reports no level-up when not eligible', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*levelup.php*' => Http::response('You need more experience to level up.')]);

    expect(StatsService::forCharacter($character)->tryLevelUp())->toBeFalse();
});
