<?php

namespace App\Game\Parsers;

use App\Game\Data\AttackRefusal;
use App\Game\Enums\AttackRefusalReason;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Classifies a refused PvP attack.
 *
 * Structural rule (combat.md): a successful attack 302s to
 * /plrattack/{battleId}/. A refused one answers 200 with the reason in the
 * body, so the caller reaches here only once it knows no redirect happened.
 *
 * The cooldown message names how long ago we hit the target, which turns a
 * refusal into a precise reschedule rather than a blind 60-minute wait.
 *
 * Fixture: pvp_attack_cooldown_refusal.html.
 */
class AttackRefusalParser
{
    /**
     * "You can only attack someone once every 60 minutes, and you attacked
     * this person 3 minutes ago." — VERIFIED 2026-08-22.
     */
    private const string COOLDOWN_PATTERN = '/only attack someone once every (\d+) minutes.*?attacked this person (\d+) minutes? ago/is';

    public function parse(string $html, ?string $finalUrl = null): AttackRefusal
    {
        // Some pages bounce to a secret-answer gate; parsing its body for an
        // attack reason would yield nonsense.
        if ($finalUrl !== null && str_contains($finalUrl, 'security_prompt')) {
            return new AttackRefusal(
                reason: AttackRefusalReason::SecurityPrompt,
                message: 'Blocked by the security prompt — answer it in the browser.',
            );
        }

        $text = $this->visibleText($html);

        if (preg_match(self::COOLDOWN_PATTERN, $text, $m)) {
            return new AttackRefusal(
                reason: AttackRefusalReason::Cooldown,
                message: $this->sentenceAround($text, 'only attack someone once every'),
                minutesSinceLastAttack: (int) $m[2],
            );
        }

        if (str_contains($text, 'security prompt') || str_contains($text, 'Security Prompt')) {
            return new AttackRefusal(
                reason: AttackRefusalReason::SecurityPrompt,
                message: 'Blocked by the security prompt — answer it in the browser.',
            );
        }

        return new AttackRefusal(
            reason: AttackRefusalReason::Unknown,
            message: $this->sentenceAround($text, 'attack') ?: 'Attack refused for an unrecognised reason.',
        );
    }

    private function visibleText(string $html): string
    {
        $stripped = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = new Crawler($stripped)->text('', false);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /** The sentence containing $needle, for a log line the user can read. */
    private function sentenceAround(string $text, string $needle): string
    {
        $position = stripos($text, $needle);

        if ($position === false) {
            return '';
        }

        $start = strrpos(substr($text, 0, $position), '.');
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($text, '.', $position);
        $end = $end === false ? strlen($text) : $end + 1;

        return trim(substr($text, $start, $end - $start));
    }
}
