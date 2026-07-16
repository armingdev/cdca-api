<?php

namespace App\Game\Parsers;

use App\Game\Data\PlayerSearchResult;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses playersearch.php results. Each row wires its attack via
 * showAttackWindow(name, playerId, defaultRage, hash) — the hash is the
 * per-render token needed for the PvP attack POST.
 */
class PlayerSearchParser
{
    /**
     * @return list<PlayerSearchResult>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $results = [];

        foreach ($crawler->filter('a') as $node) {
            $anchor = new Crawler($node);
            $onclick = (string) $anchor->attr('onclick');

            if (! preg_match("/showAttackWindow\('(.*?)','(\d+)','(\d+)','([a-f0-9]+)'\)/", $onclick, $m)) {
                continue;
            }

            $results[(int) $m[2]] = new PlayerSearchResult(
                name: $m[1],
                playerId: (int) $m[2],
                defaultRage: (int) $m[3],
                hash: $m[4],
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
