<?php

use App\Game\Enums\TargetAttackability;
use App\Game\Parsers\HitlistParser;
use App\Game\Parsers\PlayerSearchParser;

it('matches attack wiring whether or not the optional redirect argument is present', function (string $onclick, int $expectedId) {
    $html = '<table><tr><td><a onclick="'.$onclick.'">Attack!</a></td><td>50</td><td>reason</td></tr></table>';

    $entries = new HitlistParser()->parse($html);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->target->playerId)->toBe($expectedId);
})->with([
    'search row (4 args)' => ["showAttackWindow('OFFENSIVE','302','500','5648d8cd')", 302],
    'crew hitlist row (5 args)' => ["showAttackWindow('Krongstein','265','500','e1bf5316', 'crew_hitlist')", 265],
]);

it('still parses the captured search results after sharing the extraction', function () {
    $results = new PlayerSearchParser()->parse(gameFixture('playersearch_results.html'));

    expect($results[0]->name)->toBe('OFFENSIVE')
        ->and($results[0]->playerId)->toBe(302)
        ->and($results[0]->hash)->toBe('5648d8cd');
});

it('maps each level colour to the game verdict', function (string $color, TargetAttackability $expected) {
    expect(TargetAttackability::fromColor($color))->toBe($expected);
})->with([
    'green is too weak' => ['#00FF00', TargetAttackability::TooWeak],
    'cyan is in range' => ['#00FFFF', TargetAttackability::InRange],
    'red is too powerful' => ['#FF0000', TargetAttackability::TooPowerful],
    'lowercase still matches' => ['#00ffff', TargetAttackability::InRange],
    'no colour is unknown' => ['', TargetAttackability::Unknown],
]);

it('treats an unknown verdict as worth attacking, since rosters carry no colour', function () {
    expect(TargetAttackability::Unknown->isWorthAttacking())->toBeTrue()
        ->and(TargetAttackability::InRange->isWorthAttacking())->toBeTrue()
        ->and(TargetAttackability::TooWeak->isWorthAttacking())->toBeFalse()
        ->and(TargetAttackability::TooPowerful->isWorthAttacking())->toBeFalse();
});
