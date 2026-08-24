<?php

namespace App\Game\Parsers;

use App\Game\Data\PlayerSearchResult;
use App\Game\Parsers\Concerns\ParsesAttackWindows;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses playersearch.php results. Each row wires its attack via
 * showAttackWindow(name, playerId, defaultRage, hash) — the hash is the
 * per-render token needed for the PvP attack POST.
 *
 * Shares its extraction with the hitlist parsers via ParsesAttackWindows, so
 * rows that pass the optional 5th `redir` argument still match.
 */
class PlayerSearchParser
{
    use ParsesAttackWindows;

    /**
     * @return list<PlayerSearchResult>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $results = [];

        foreach ($crawler->filter('a') as $node) {
            $anchor = new Crawler($node);
            $attack = $this->attackWindowFrom($anchor);

            if ($attack === null) {
                continue;
            }

            $results[$attack['playerId']] = new PlayerSearchResult(
                name: $attack['name'],
                playerId: $attack['playerId'],
                defaultRage: $attack['rage'],
                hash: $attack['hash'],
                level: $this->levelFromRow($anchor),
            );
        }

        return array_values($results);
    }

    private function levelFromRow(Crawler $anchor): ?int
    {
        $row = $anchor->closest('tr');

        if ($row === null) {
            return null;
        }

        $cells = $row->filter('td');

        // Row layout: Name | Level (align right) | Actions.
        if ($cells->count() < 2) {
            return null;
        }

        $level = trim($cells->eq(1)->text());

        return is_numeric($level) ? (int) $level : null;
    }
}
