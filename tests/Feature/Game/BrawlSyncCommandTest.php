<?php

use App\Game\Enums\BrawlType;
use App\Models\BrawlRound;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('records both brawl schedules from the game pages', function () {
    Character::factory()->for(Rga::factory()->withSession())->create(['server_id' => 1]);

    Http::fake(['*closedpvp*' => Http::response(gameFixture('closedpvp_brawl_prestart.html'))]);

    $this->artisan('outwar:brawl-sync')
        ->assertSuccessful()
        ->expectsOutputToContain('PvP Brawl')
        ->expectsOutputToContain('Faction Brawl');

    expect(BrawlRound::count())->toBe(2)
        ->and(BrawlRound::where('type', BrawlType::Pvp->value)->first()->starts_at->toIso8601String())
        ->toBe('2026-08-31T13:00:00+00:00');
});

it('reads the schedule once per server, not once per character', function () {
    $rga = Rga::factory()->withSession()->create();
    Character::factory()->count(3)->for($rga)->create(['server_id' => 1]);

    Http::fake(['*closedpvp*' => Http::response(gameFixture('closedpvp_brawl_prestart.html'))]);

    $this->artisan('outwar:brawl-sync')->assertSuccessful();

    // Two page reads: one per brawl type, for the single server.
    Http::assertSentCount(2);
});

it('fails clearly when no character has a live session', function () {
    Character::factory()->for(Rga::factory())->create();

    $this->artisan('outwar:brawl-sync')
        ->assertFailed()
        ->expectsOutputToContain('No character with a live session');
});
