<?php

use App\Game\Enums\BattleOutcome;
use App\Game\Parsers\AttackLogParser;

it('parses the outgoing attack log into per-opponent entries', function () {
    $entries = new AttackLogParser()->parse(gameFixture('attacklog_out.html'));

    expect($entries)->toHaveCount(3);

    $latest = $entries[0];

    expect($latest->opponentName)->toBe('azraid5')
        ->and($latest->opponentPlayerId)->toBe(105387)
        ->and($latest->outcome)->toBe(BattleOutcome::Win)
        ->and($latest->battleId)->toBe(24791420476);
});

it('reads log timestamps on the game clock, which is UTC-5 with no DST', function () {
    $entries = new AttackLogParser()->parse(gameFixture('attacklog_out.html'));

    // Rendered as "8/22/2026 3:50am" game time — 08:50 UTC.
    expect($entries[0]->occurredAt->toIso8601String())->toBe('2026-08-22T08:50:00+00:00');

    // Using America/New_York instead would land an hour off in summer, which
    // is exactly the drift the fixed offset exists to prevent.
    expect($entries[0]->occurredAt->format('H:i'))->toBe('08:50');
});

it('keys entries on the opponent id, since names are mutable but ids are not', function () {
    $entries = new AttackLogParser()->parse(gameFixture('attacklog_out.html'));

    expect(array_map(fn ($entry) => $entry->opponentPlayerId, $entries))
        ->toBe([105387, 105385, 105384]);
});

it('ignores rows that carry no opponent link', function () {
    $html = '<table><tr><td>No attacks</td><td>-</td><td>-</td></tr></table>';

    expect(new AttackLogParser()->parse($html))->toBe([]);
});
