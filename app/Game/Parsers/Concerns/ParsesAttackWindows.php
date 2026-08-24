<?php

namespace App\Game\Parsers\Concerns;

use App\Game\Data\AttackTarget;
use App\Game\Enums\TargetAttackability;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every page that can launch an attack wires it the same way (main.js,
 * VERIFIED 2026-08-22):
 *
 *   showAttackWindow(name, defender_id, rage, hash[, redir])
 *     → POST /somethingelse.php?attackid={defender_id}&r={redir}
 *       body message & rage & hash
 *
 * The optional 5th argument is the return context ('crew_hitlist', …) and is
 * cosmetic. Search results omit it entirely, which is why `r=undefined`
 * shows up in captured requests.
 */
trait ParsesAttackWindows
{
    /**
     * The attack wiring on an anchor, or null when it carries none.
     *
     * @return array{name: string, playerId: int, rage: int, hash: string}|null
     */
    protected function attackWindowFrom(Crawler $anchor): ?array
    {
        $onclick = (string) $anchor->attr('onclick');

        // The 5th argument is optional — crew hitlist rows pass it, search
        // result rows do not.
        if (! preg_match("/showAttackWindow\('(.*?)','(\d+)','(\d+)','([a-f0-9]+)'/", $onclick, $m)) {
            return null;
        }

        return [
            'name' => $m[1],
            'playerId' => (int) $m[2],
            'rage' => (int) $m[3],
            'hash' => $m[4],
        ];
    }

    /**
     * The level cell's value and the game's own attackability verdict, read
     * from its `<font color>` — green too weak, cyan in range, red too
     * powerful.
     *
     * @return array{level: int|null, attackability: TargetAttackability}
     */
    protected function levelFrom(Crawler $row, int $cellIndex): array
    {
        $cells = $row->filter('td');

        if ($cells->count() <= $cellIndex) {
            return ['level' => null, 'attackability' => TargetAttackability::Unknown];
        }

        $cell = $cells->eq($cellIndex);
        $font = $cell->filter('font');
        $text = trim($cell->text());

        return [
            'level' => is_numeric($text) ? (int) $text : null,
            'attackability' => TargetAttackability::fromColor(
                $font->count() > 0 ? $font->attr('color') : null,
            ),
        ];
    }

    /** Build a ready-to-attack target from an anchor's attack wiring. */
    protected function targetFrom(Crawler $anchor, ?int $level, TargetAttackability $attackability): ?AttackTarget
    {
        $attack = $this->attackWindowFrom($anchor);

        if ($attack === null) {
            return null;
        }

        return new AttackTarget(
            playerId: $attack['playerId'],
            name: $attack['name'],
            level: $level,
            attackability: $attackability,
            hash: $attack['hash'],
            rageCost: $attack['rage'],
        );
    }

    /** Extract a player id from a `profile.php?id=` / `profile?id=` href. */
    protected function playerIdFromHref(?string $href): ?int
    {
        if ($href !== null && preg_match('/profile(?:\.php)?\?id=(\d+)/', $href, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
