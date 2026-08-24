<?php

use App\Game\Enums\TargetAttackability;
use App\Game\Parsers\HitlistParser;

it('parses the personal hitlist into targets carrying their attack hash', function () {
    $entries = new HitlistParser()->parse(gameFixture('myhitlist.html'));

    expect($entries)->toHaveCount(1);

    $entry = $entries[0];

    expect($entry->target->name)->toBe('iRoNIvIaiDeN')
        ->and($entry->target->playerId)->toBe(14620)
        ->and($entry->target->level)->toBe(40)
        ->and($entry->target->hash)->toBe('f49cf6ab')
        ->and($entry->target->rageCost)->toBe(500)
        ->and($entry->target->isReadyToAttack())->toBeTrue()
        ->and($entry->reason)->toBe('mali')
        ->and($entry->hits)->toBe(0);
});

it('reads the game level-colour verdict rather than computing a level rule', function () {
    $entries = new HitlistParser()->parse(gameFixture('myhitlist.html'));

    // #00FFFF on the level cell = the game saying "in range to attack".
    expect($entries[0]->target->attackability)->toBe(TargetAttackability::InRange)
        ->and($entries[0]->target->attackability->isWorthAttacking())->toBeTrue();
});

it('parses the crew hitlist, whose ordinal column shifts every cell right', function () {
    $entries = new HitlistParser()->parse(gameFixture('crew_hitlist.html'));

    expect(count($entries))->toBeGreaterThanOrEqual(2);

    $first = $entries[0];

    expect($first->target->name)->toBe('Krongstein')
        ->and($first->target->playerId)->toBe(265)
        ->and($first->target->level)->toBe(95)
        ->and($first->target->hash)->toBe('e1bf5316')
        ->and($first->target->attackability)->toBe(TargetAttackability::InRange)
        ->and($first->reason)->toBe('DDCT Rocks')
        ->and($first->postedByName)->toBe('zProfound01')
        ->and($first->postedById)->toBe(267642);

    expect($entries[1]->target->name)->toBe('StarPower')
        ->and($entries[1]->target->playerId)->toBe(158701)
        ->and($entries[1]->target->hash)->toBe('3d9d070d');
});

it('gives every crew hitlist row its own distinct hash', function () {
    $entries = new HitlistParser()->parse(gameFixture('crew_hitlist.html'));

    $hashes = array_map(fn ($entry) => $entry->target->hash, $entries);

    expect($hashes)->toEqual(array_unique($hashes))
        ->and($hashes)->not->toContain(null);
});

it('returns an empty list for a page with no hitlist rows', function () {
    expect(new HitlistParser()->parse('<html><body><table></table></body></html>'))->toBe([]);
});
