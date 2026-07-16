<?php

use App\Game\Parsers\PlayerSearchParser;

it('parses the captured search results into targets with their attack hash', function () {
    $results = new PlayerSearchParser()->parse(gameFixture('playersearch_results.html'));

    expect(count($results))->toBeGreaterThanOrEqual(2);

    $offensive = $results[0];

    expect($offensive->name)->toBe('OFFENSIVE')
        ->and($offensive->playerId)->toBe(302)
        ->and($offensive->defaultRage)->toBe(500)
        ->and($offensive->hash)->toBe('5648d8cd')
        ->and($offensive->level)->toBe(71);

    $second = $results[1];

    expect($second->name)->toBe('offensive2')
        ->and($second->playerId)->toBe(3609)
        ->and($second->hash)->toBe('00d1939e');
});

it('returns an empty list when there are no attackable players', function () {
    expect(new PlayerSearchParser()->parse('<html><body>No results</body></html>'))->toBe([]);
});
