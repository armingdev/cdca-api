<?php

use App\Game\Exceptions\ParseException;
use App\Game\Parsers\UserStatsParser;

it('parses the captured userstats fixture', function () {
    $stats = new UserStatsParser()->parse(gameFixture('userstats_response.json'));

    expect($stats->exp)->toBe(80906)
        ->and($stats->rage)->toBe(2000)
        ->and($stats->level)->toBe(19);
});

it('handles huge comma-formatted exp values', function () {
    $stats = new UserStatsParser()->parse('{"exp":"169,548,518,310","rage":"244,093","level":"95","width":-300}');

    expect($stats->exp)->toBe(169_548_518_310)
        ->and($stats->rage)->toBe(244_093);
});

it('throws when the response is not the stats payload', function () {
    new UserStatsParser()->parse('<html>login page</html>');
})->throws(ParseException::class);
