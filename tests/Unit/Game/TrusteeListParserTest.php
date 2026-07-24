<?php

use App\Game\Exceptions\ParseException;
use App\Game\Parsers\TrusteeListParser;

it('parses own characters and trustees, skipping the change-server entry', function () {
    $entries = new TrusteeListParser()->parse(gameFixture('trustee_list.json'));

    expect($entries)->toHaveCount(5);

    $own = array_values(array_filter($entries, fn ($entry) => ! $entry->isTrustee));
    $trustees = array_values(array_filter($entries, fn ($entry) => $entry->isTrustee));

    expect($own)->toHaveCount(3)
        ->and($own[0]->suid)->toBe(7257)
        ->and($own[0]->name)->toBe('PLAYER1')
        ->and($trustees)->toHaveCount(2)
        ->and($trustees[0]->suid)->toBe(27285)
        ->and($trustees[0]->name)->toBe('TRUSTEE1');
});

it('throws on a non-json response', function () {
    new TrusteeListParser()->parse('<html>You must be logged in</html>');
})->throws(ParseException::class);
