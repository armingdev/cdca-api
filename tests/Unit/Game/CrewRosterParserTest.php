<?php

use App\Game\Parsers\CrewRosterParser;

it('parses a rival crew profile into its roster', function () {
    $roster = new CrewRosterParser()->parse(gameFixture('crew_profile_members.html'), 17785);

    expect($roster->crewId)->toBe(17785)
        ->and($roster->name)->toBe('Asylum')
        ->and($roster->leader)->toBe('DaInfamousScareCr0w')
        ->and($roster->totalMembers)->toBe(33)
        ->and($roster->averageLevel)->toBe(47);

    $first = $roster->members[0];

    expect($first->name)->toBe('DaInfamousScareCr0w')
        ->and($first->playerId)->toBe(267464)
        ->and($first->level)->toBe(70)
        ->and($first->rank)->toBe('Leader');
});

it('returns members without an attack hash, since the roster renders no attack icon', function () {
    $roster = new CrewRosterParser()->parse(gameFixture('crew_profile_members.html'), 17785);

    $target = $roster->members[0]->toAttackTarget();

    expect($target->hash)->toBeNull()
        ->and($target->isReadyToAttack())->toBeFalse()
        ->and($target->playerId)->toBe(267464);
});

it('does not mistake ally or enemy crew links for members', function () {
    $roster = new CrewRosterParser()->parse(gameFixture('crew_profile_members.html'), 17785);

    foreach ($roster->members as $member) {
        expect($member->name)->not->toBe('Collective');
    }

    expect(count($roster->members))->toBeGreaterThanOrEqual(4);
});

it('handles a crew page with no roster rows', function () {
    $roster = new CrewRosterParser()->parse('<html><body><h2>Empty</h2></body></html>', 1);

    expect($roster->members)->toBe([])
        ->and($roster->name)->toBe('Empty');
});
