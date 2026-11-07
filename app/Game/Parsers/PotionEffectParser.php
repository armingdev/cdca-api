<?php

namespace App\Game\Parsers;

use App\Game\Data\PotionEffect;
use App\Game\Enums\PotionKind;

/**
 * Classifies a potion from its item-database description. The text is free
 * form — "for 60 mins", "Duration: 4 hours", "(60 minute duration)", "Lasts
 * two hours", "HitDuration: 240 mins", "Awards 1,000 Rage upon consumption"
 * — so the rules are: a stated duration makes it a buff; otherwise an
 * instant rage award (or a rage refill) makes it a rage potion; anything else
 * is never used by a run.
 */
class PotionEffectParser
{
    /** Never auto-used: it blocks attacking for a day or a week. */
    private const array NEVER_AUTO = ['PvP Immunity'];

    private const array UNIT_MINUTES = [
        'min' => 1,
        'minute' => 1,
        'hour' => 60,
        'hr' => 60,
        'day' => 1440,
        'week' => 10080,
    ];

    private const array WORD_NUMBERS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'ten' => 10, 'twelve' => 12,
    ];

    public function parse(string $name, string $description): PotionEffect
    {
        $duration = $this->durationMinutes($description);
        $rage = $this->rageAmount($description);

        foreach (self::NEVER_AUTO as $blocked) {
            if (str_contains($name, $blocked)) {
                return new PotionEffect(PotionKind::Other, $duration);
            }
        }

        if ($duration !== null) {
            return new PotionEffect(PotionKind::Buff, $duration, $rage);
        }

        if ($rage !== null || preg_match('/restores\b.*\brage\b/i', $description) === 1) {
            return new PotionEffect(PotionKind::Rage, null, $rage);
        }

        return new PotionEffect(PotionKind::Other);
    }

    /**
     * The first "<number> <time unit>" in the text. Per-turn and per-hour
     * rates ("+100 rage/turn", "500 exp/hr") never match: the unit must
     * follow the number directly.
     */
    private function durationMinutes(string $description): ?int
    {
        $numbers = implode('|', array_keys(self::WORD_NUMBERS));

        if (preg_match("/\\b(\\d+|{$numbers})\\s*(minute|min|hour|hr|day|week)s?\\b/i", $description, $match) !== 1) {
            return null;
        }

        $amount = ctype_digit($match[1]) ? (int) $match[1] : self::WORD_NUMBERS[strtolower($match[1])];

        return $amount * self::UNIT_MINUTES[strtolower($match[2])];
    }

    /**
     * "Awards 1,000 Rage", "+600 rage", "2,500 extra rage" — but not a
     * regeneration rate ("+100 rage/turn", "575 Rage Per Turn").
     */
    private function rageAmount(string $description): ?int
    {
        if (preg_match('/(\d[\d,]*)\s+(?:extra\s+)?rage\b(?!\s*\/|\s+per\s+turn)/i', $description, $match) !== 1) {
            return null;
        }

        return (int) str_replace(',', '', $match[1]);
    }
}
