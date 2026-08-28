<?php

use App\Game\GameClock;
use Carbon\CarbonImmutable;

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

it('puts the next rage tick on the coming hour boundary', function () {
    // Rage regenerates on the hour of the game's clock, and its whole-hour
    // offset makes that a UTC boundary too, so 12:40 UTC waits for 13:00 UTC.
    $tick = GameClock::nextRageTickAt(CarbonImmutable::parse('2026-08-27 12:40:00', 'UTC'));

    expect($tick->toDateTimeString())->toBe('2026-08-27 13:00:30');
});

it('never points the rage tick at an hour that has already passed', function () {
    // A second short of the hour waits for the next one, and landing exactly
    // on it waits for the one after — never for the tick just missed.
    $justBefore = GameClock::nextRageTickAt(CarbonImmutable::parse('2026-08-27 12:59:59', 'UTC'));
    $onTheHour = GameClock::nextRageTickAt(CarbonImmutable::parse('2026-08-27 13:00:00', 'UTC'));

    expect($justBefore->toDateTimeString())->toBe('2026-08-27 13:00:30')
        ->and($onTheHour->toDateTimeString())->toBe('2026-08-27 14:00:30');
});

it('returns null for a timestamp it cannot read', function () {
    expect(GameClock::parse('not a date'))->toBeNull();
});

it('tolerates the padded whitespace the game renders inside table cells', function () {
    expect(GameClock::parse("\n\t 8/22/2026 3:50am \n")?->toIso8601String())
        ->toBe('2026-08-22T08:50:00+00:00');
});
