<?php

namespace App\Game\Parsers;

use App\Game\Data\HitlistEntry;
use App\Game\Parsers\Concerns\ParsesAttackWindows;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses both hitlist pages, which share a layout:
 *
 *   /myhitlist     Name | Level | Reason | Hits | Remove
 *   /crew_hitlist  #    | Name  | Level  | Reason | Crew hits | Posted by | Remove
 *
 * Both render an attack icon per row, so every entry arrives with a fresh
 * attack hash. The crew list came back with 404 rows in a single ~80KB
 * response and no pagination — one GET is a whole run's worth of targets.
 *
 * Fixtures: myhitlist.html, crew_hitlist.html.
 */
class HitlistParser
{
    use ParsesAttackWindows;

    /**
     * @return list<HitlistEntry>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $entries = [];

        foreach ($crawler->filter('tr') as $node) {
            $row = new Crawler($node);
            $entry = $this->entryFromRow($row);

            if ($entry !== null) {
                // Keyed by player id so a target listed twice is attacked once.
                $entries[$entry->target->playerId] = $entry;
            }
        }

        return array_values($entries);
    }

    private function entryFromRow(Crawler $row): ?HitlistEntry
    {
        $anchors = $row->filter('a[onclick]');

        if ($anchors->count() === 0) {
            return null;
        }

        $attackAnchor = null;

        foreach ($anchors as $node) {
            $anchor = new Crawler($node);

            if ($this->attackWindowFrom($anchor) !== null) {
                $attackAnchor = $anchor;
                break;
            }
        }

        if ($attackAnchor === null) {
            return null;
        }

        // The crew list prefixes an ordinal cell, shifting every column right.
        $offset = $this->columnOffset($row);
        $level = $this->levelFrom($row, $offset + 1);

        $target = $this->targetFrom($attackAnchor, $level['level'], $level['attackability']);

        if ($target === null) {
            return null;
        }

        $cells = $row->filter('td');
        $postedBy = $this->postedBy($row);

        return new HitlistEntry(
            target: $target,
            reason: $cells->count() > $offset + 2 ? trim($cells->eq($offset + 2)->text()) : '',
            hits: $this->hits($row),
            postedById: $postedBy['id'],
            postedByName: $postedBy['name'],
        );
    }

    /**
     * Crew rows lead with an ordinal cell ("1."); personal rows start at the
     * name. Detect by whether the first cell is just a number.
     */
    private function columnOffset(Crawler $row): int
    {
        $cells = $row->filter('td');

        if ($cells->count() === 0) {
            return 0;
        }

        $first = trim($cells->eq(0)->text());

        return preg_match('/^\d+\.?$/', $first) === 1 ? 1 : 0;
    }

    /** The hit tally, wired as `pophits({playerId})` on its own anchor. */
    private function hits(Crawler $row): ?int
    {
        foreach ($row->filter('a') as $node) {
            $anchor = new Crawler($node);

            if (str_contains((string) $anchor->attr('href'), 'pophits(')) {
                $text = trim($anchor->text());

                return is_numeric($text) ? (int) $text : null;
            }
        }

        return null;
    }

    /**
     * Crew rows credit the poster with a profile link that is not the target's
     * own — the target's link sits in the name cell alongside the attack icon.
     *
     * @return array{id: int|null, name: string|null}
     */
    private function postedBy(Crawler $row): array
    {
        $profileLinks = [];

        foreach ($row->filter('a[href]') as $node) {
            $anchor = new Crawler($node);
            $playerId = $this->playerIdFromHref($anchor->attr('href'));

            if ($playerId !== null) {
                $profileLinks[] = ['id' => $playerId, 'name' => trim($anchor->text())];
            }
        }

        // First link is the target; a second one is the poster.
        return $profileLinks[1] ?? ['id' => null, 'name' => null];
    }
}
