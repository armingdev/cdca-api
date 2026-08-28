<?php

namespace App\Game;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The game renders every timestamp in its own fixed clock.
 *
 * VERIFIED 2026-08-22: the Brawl countdown ships `var countdown = 1788181200
 * - now` and renders that instant as "August 31st 8:00AM". 1788181200 is
 * 13:00 UTC, so the game clock is **UTC-5 all year** — deliberately not
 * `America/New_York`, which would drift by an hour across DST and silently
 * mis-schedule every brawl window and cooldown for half the year.
 */
final class GameClock
{
    public const string OFFSET = '-05:00';

    /** Grace added to the hour boundary before we trust the tick to have landed. */
    private const int RAGE_TICK_BUFFER_SECONDS = 30;

    /**
     * Parse a game-rendered timestamp (e.g. attack log `8/22/2026 3:50am`)
     * into a UTC instant.
     */
    public static function parse(string $stamp, string $format = 'n/j/Y g:ia'): ?CarbonImmutable
    {
        // Carbon throws on a malformed value rather than returning false; a
        // single odd cell must skip its row, not abort the run.
        try {
            $parsed = CarbonImmutable::createFromFormat(
                $format,
                trim(preg_replace('/\s+/', ' ', $stamp) ?? ''),
                self::OFFSET,
            );
        } catch (Throwable) {
            return null;
        }

        return $parsed?->utc();
    }

    /** Interpret a unix timestamp emitted by the game (already absolute). */
    public static function fromTimestamp(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp);
    }

    /** "Now", expressed on the game's clock — for rendering and day maths. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::OFFSET);
    }

    /**
     * When rage next regenerates: the game tops characters up on the hour of
     * its own clock. The offset is a whole number of hours, so a game-clock
     * hour boundary is also a UTC one — the conversion is a no-op, and writing
     * it this way keeps the assumption visible if the offset ever changes.
     *
     * The buffer absorbs clock skew; waking a second early would burn a whole
     * cycle rediscovering that the rage has not landed yet.
     */
    public static function nextRageTickAt(?CarbonImmutable $from = null): CarbonImmutable
    {
        return ($from?->setTimezone(self::OFFSET) ?? self::now())
            ->addHour()
            ->startOfHour()
            ->addSeconds(self::RAGE_TICK_BUFFER_SECONDS)
            ->utc();
    }
}
