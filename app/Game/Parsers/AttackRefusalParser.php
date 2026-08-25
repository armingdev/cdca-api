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
 * Fixtures: pvp_attack_cooldown_refusal.html (content region),
 * pvp_attack_refusal_full_page.html (the whole document, as the engine sees it).
 */
class AttackRefusalParser
{
    /**
     * "You can only attack someone once every 60 minutes, and you attacked
     * this person 3 minutes ago." — VERIFIED 2026-08-22.
     */
    private const string COOLDOWN_PATTERN = '/only attack someone once every (\d+) minutes.*?attacked this person (\d+) minutes? ago/is';

    /**
     * Refusals whose wording is known, matched in order. All VERIFIED
     * 2026-08-25 from live crew-hitlist runs — the phrasings are the game's,
     * copied verbatim from the refusal bodies.
     *
     * Bounded with `[^.]` rather than `.*` so a match can never run past its
     * own sentence into the rest of the page.
     */
    private const array PATTERNS = [
        '/[^.]*is your ally[^.]*/i' => AttackRefusalReason::Allied,
        '/[^.]*member of an Allied crew[^.]*\.?/i' => AttackRefusalReason::Allied,
        '/[^.]*under the effects of PVP Immunity[^.]*\.?/i' => AttackRefusalReason::PvpImmunity,
    ];

    /** Keeps a message readable, and inside the `fail_reason` column. */
    private const int MAX_MESSAGE = 180;

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
                // The matched span is the sentence itself — no scanning
                // outward for punctuation, which is what used to drag the
                // whole navigation menu into the message.
                message: $this->clip($m[0]),
                minutesSinceLastAttack: (int) $m[2],
            );
        }

        foreach (self::PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $text, $m)) {
                return new AttackRefusal(reason: $reason, message: $this->clip($m[0]));
            }
        }

        if (stripos($text, 'security prompt') !== false) {
            return new AttackRefusal(
                reason: AttackRefusalReason::SecurityPrompt,
                message: 'Blocked by the security prompt — answer it in the browser.',
            );
        }

        return $this->unknown($text);
    }

    /**
     * An unrecognised refusal is a gap in our knowledge, not a nuisance: the
     * game has refusal reasons we never captured (level bands, protections).
     * The caller logs it with the character and target context so the next one
     * can be classified rather than guessed at.
     */
    private function unknown(string $text): AttackRefusal
    {
        $message = $this->clip($text);

        return new AttackRefusal(
            reason: AttackRefusalReason::Unknown,
            message: $message !== '' ? $message : 'Attack refused for an unrecognised reason.',
        );
    }

    /**
     * The page's own content, without the chrome.
     *
     * Scoping to `#content` matters: the surrounding navigation and the attack
     * and security modals are boilerplate present on *every* page, and reading
     * them as if they were the refusal produced a message hundreds of
     * characters long built from the main menu.
     *
     * The footer sits *inside* `#content`, so it has to be removed as well —
     * without that, every unrecognised refusal ends with "Privacy Policy |
     * Terms of Service | Purchase Policy | Contact Info".
     */
    private function visibleText(string $html): string
    {
        $stripped = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        $crawler = new Crawler($stripped);
        $content = $crawler->filter('#content');
        $scope = $content->count() > 0 ? $content : $crawler;

        foreach ($scope->filter('.footer-wrapper, #foot_box, .footer-section') as $node) {
            $node->parentNode?->removeChild($node);
        }

        return trim(preg_replace('/\s+/', ' ', $scope->text('', false)) ?? '');
    }

    private function clip(string $text): string
    {
        return str($text)->squish()->limit(self::MAX_MESSAGE)->toString();
    }
}
