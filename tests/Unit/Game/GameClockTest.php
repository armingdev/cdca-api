<?php

use App\Game\GameClock;

it('reads game timestamps on a fixed UTC-5 offset', function () {
    expect(GameClock::parse('8/22/2026 3:50am')?->toIso8601String())
        ->toBe('2026-08-22T08:50:00+00:00');
});

it('does not shift with daylight saving, unlike America/New_York', function () {
    // Same wall-clock time in January and August must be the same UTC offset.
    $winter = GameClock::parse('1/15/2026 8:00am');
    $summer = GameClock::parse('7/15/2026 8:00am');

    expect($winter?->format('H:i'))->toBe('13:00')
        ->and($summer?->format('H:i'))->toBe('13:00');
});

it('reads the brawl countdown epoch as an absolute instant', function () {
    // The value the /closedpvp FlipClock counts down to.
    expect(GameClock::fromTimestamp(1788181200)->toIso8601String())
        ->toBe('2026-08-31T13:00:00+00:00');
});

it('returns null for a timestamp it cannot read', function () {
    expect(GameClock::parse('not a date'))->toBeNull();
});

it('tolerates the padded whitespace the game renders inside table cells', function () {
    expect(GameClock::parse("\n\t 8/22/2026 3:50am \n")?->toIso8601String())
        ->toBe('2026-08-22T08:50:00+00:00');
});
