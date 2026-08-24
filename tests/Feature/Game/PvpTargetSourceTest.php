<?php

use App\Game\Combat\Targets\AttackListTargetSource;
use App\Game\Combat\Targets\BrawlTargetSource;
use App\Game\Combat\Targets\CrewMembersTargetSource;
use App\Game\Combat\Targets\HitlistTargetSource;
use App\Game\Enums\BrawlType;
use App\Game\Enums\TargetAttackability;
use App\Models\AttackList;
use App\Models\BrawlRound;
use App\Models\Character;
use App\Models\Crew;
use App\Models\PlayerCharacter;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function pvpCharacter(): Character
{
    return Character::factory()->for(Rga::factory()->withSession())->create(['server_id' => 1]);
}

it('pulls the whole crew hitlist in a single request, hashes included', function () {
    $character = pvpCharacter();

    Http::fake(['*crew_hitlist*' => Http::response(gameFixture('crew_hitlist.html'))]);

    $targets = HitlistTargetSource::crew($character)->targets();

    expect(count($targets))->toBeGreaterThanOrEqual(2)
        ->and($targets[0]->name)->toBe('Krongstein')
        ->and($targets[0]->isReadyToAttack())->toBeTrue();

    // One GET for the entire list — no per-target request.
    Http::assertSentCount(1);
});

it('reads the personal hitlist from its own page', function () {
    $character = pvpCharacter();

    Http::fake(['*myhitlist*' => Http::response(gameFixture('myhitlist.html'))]);

    $targets = HitlistTargetSource::personal($character)->targets();

    expect($targets)->toHaveCount(1)
        ->and($targets[0]->name)->toBe('iRoNIvIaiDeN');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'myhitlist'));
});

it('records every hitlist sighting in the target registry', function () {
    $character = pvpCharacter();

    Http::fake(['*crew_hitlist*' => Http::response(gameFixture('crew_hitlist.html'))]);

    HitlistTargetSource::crew($character)->targets();

    $known = PlayerCharacter::where('player_id', 265)->first();

    expect($known->name)->toBe('Krongstein')
        ->and($known->level)->toBe(95)
        ->and($known->attackability)->toBe(TargetAttackability::InRange);
});

it('pulls a rival crew roster and remembers the crew', function () {
    $character = pvpCharacter();

    Http::fake(['*crew_profile.php*' => Http::response(gameFixture('crew_profile_members.html'))]);

    $targets = CrewMembersTargetSource::forCrew($character, 17785)->targets();

    expect(count($targets))->toBeGreaterThanOrEqual(4)
        ->and($targets[0]->name)->toBe('DaInfamousScareCr0w');

    $crew = Crew::where('game_crew_id', 17785)->first();

    expect($crew->name)->toBe('Asylum')
        ->and($crew->total_members)->toBe(33)
        ->and($crew->members()->count())->toBe(count($targets));
});

it('returns crew members without a hash, since the roster renders no attack icon', function () {
    $character = pvpCharacter();

    Http::fake(['*crew_profile.php*' => Http::response(gameFixture('crew_profile_members.html'))]);

    $targets = CrewMembersTargetSource::forCrew($character, 17785)->targets();

    expect($targets[0]->isReadyToAttack())->toBeFalse();
});

it('resolves attack list names to ids and caches them back onto the list', function () {
    $character = pvpCharacter();
    $list = AttackList::factory()->create(['name' => 'Rivals']);
    $entry = $list->addTarget('OFFENSIVE');

    Http::fake(['*playersearch.php*' => Http::response(gameFixture('playersearch_results.html'))]);

    $targets = AttackListTargetSource::forList($character, $list->fresh())->targets();

    expect($targets)->toHaveCount(1)
        ->and($targets[0]->playerId)->toBe(302)
        ->and($targets[0]->isReadyToAttack())->toBeTrue()
        ->and($entry->fresh()->player_id)->toBe(302);
});

it('skips attack list names the search cannot find rather than failing the run', function () {
    $character = pvpCharacter();
    $list = AttackList::factory()->create();
    $list->addTarget('NoSuchPlayer');

    Http::fake(['*playersearch.php*' => Http::response('<html>No results</html>')]);

    expect(AttackListTargetSource::forList($character, $list->fresh())->targets())->toBe([]);
});

it('reads brawl participants and records the round schedule', function () {
    $character = pvpCharacter();

    Http::fake(['*closedpvp*' => Http::response(gameFixture('closedpvp_brawl_prestart.html'))]);

    $source = BrawlTargetSource::forType($character, BrawlType::Pvp);
    $targets = $source->targets();

    expect($targets)->toHaveCount(3)
        ->and($targets[0]->name)->toBe('Oddy');

    $round = BrawlRound::first();

    expect($round->round_id)->toBe(179)
        ->and($round->type)->toBe(BrawlType::Pvp)
        ->and($round->starts_at->toIso8601String())->toBe('2026-08-31T13:00:00+00:00')
        // 08:00-20:00 game time is 13:00-01:00 UTC, so the close crosses midnight.
        ->and($round->ends_at->toIso8601String())->toBe('2026-09-01T01:00:00+00:00');
});

it('excludes the running character from its own brawl target list', function () {
    // Oddy (113903) is one of the three registrants.
    $character = Character::factory()->for(Rga::factory()->withSession())->create([
        'server_id' => 1,
        'suid' => 113903,
    ]);

    Http::fake(['*closedpvp*' => Http::response(gameFixture('closedpvp_brawl_prestart.html'))]);

    $source = BrawlTargetSource::forType($character, BrawlType::Pvp);

    expect($source->isEntered())->toBeTrue()
        ->and($source->targets())->toHaveCount(2);
});

it('knows when the character is not registered for the brawl', function () {
    $character = pvpCharacter();

    Http::fake(['*closedpvp*' => Http::response(gameFixture('closedpvp_brawl_prestart.html'))]);

    expect(BrawlTargetSource::forType($character, BrawlType::Pvp)->isEntered())->toBeFalse();
});
