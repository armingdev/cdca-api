<?php

namespace App\Game\Parsers;

use App\Game\Data\BrawlPage;
use App\Game\Data\BrawlStanding;
use App\Game\Enums\BrawlType;
use App\Game\GameClock;
use App\Game\Parsers\Concerns\ParsesAttackWindows;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses `/closedpvp` (PvP Brawl) and `/closedpvp?type=1` (Faction Brawl),
 * which share one layout.
 *
 * The standings table doubles as the participant list — before the window
 * opens it lists registrants on 0 wins, which is also how a character checks
 * whether it is entered.
 *
 * The start time is taken from the FlipClock countdown's unix timestamp
 * rather than the rendered "August 31st 8:00AM" label: the epoch is
 * unambiguous, the label carries no year and no timezone.
 *
 * Fixture: closedpvp_brawl_prestart.html.
 */
class BrawlPageParser
{
    use ParsesAttackWindows;

    public function parse(string $html, BrawlType $type): BrawlPage
    {
        $crawler = new Crawler($html);
        $countdown = $this->countdown($html);

        return new BrawlPage(
            type: $type,
            standings: $this->standings($crawler),
            roundId: $countdown['roundId'] ?? $this->roundIdFromPreviousLink($html),
            startsAt: isset($countdown['startsAt']) ? GameClock::fromTimestamp($countdown['startsAt']) : null,
            participantCount: $this->intListValue($crawler, 'Participants'),
            startDateLabel: $this->listValue($crawler, 'Start Date'),
            endDateLabel: $this->listValue($crawler, 'End Date'),
            canEnter: str_contains($html, 'enter=1'),
        );
    }

    /**
     * `var countdown = 1788181200 - ...` gives the start instant, and the
     * FlipClock wrapper class `clock-builder-output-{roundId}` the round.
     *
     * @return array{startsAt?: int, roundId?: int}
     */
    private function countdown(string $html): array
    {
        $found = [];

        if (preg_match('/var\s+countdown\s*=\s*(\d{9,})/', $html, $m)) {
            $found['startsAt'] = (int) $m[1];
        }

        if (preg_match('/clock-builder-output-(\d+)/', $html, $m)) {
            $found['roundId'] = (int) $m[1];
        }

        return $found;
    }

    /**
     * Fallback: the "Previous tournament" link names the prior round, so the
     * current one is the next id of this type — rounds interleave by type,
     * stepping 2 at a time.
     */
    private function roundIdFromPreviousLink(string $html): ?int
    {
        if (preg_match('/closedpvp\?roundid=(\d+)/', $html, $m)) {
            return (int) $m[1] + 2;
        }

        return null;
    }

    /**
     * The page carries four profile-linking tables — previous champions,
     * all-time top 10, the reward table and the live standings — and the
     * all-time table shares the standings' four-column shape
     * (`Rank | Character | Wins | Date`). So select the table by its header
     * signature rather than by column count, or the parser silently returns
     * historic champions as attackable participants.
     *
     * @return list<BrawlStanding>
     */
    private function standings(Crawler $crawler): array
    {
        foreach ($crawler->filter('table') as $node) {
            $table = new Crawler($node);

            if ($this->isStandingsTable($table)) {
                return $this->standingsRows($table);
            }
        }

        return [];
    }

    private function isStandingsTable(Crawler $table): bool
    {
        $headers = $table->filter('thead th')->each(
            fn (Crawler $th): string => strtolower(trim($th->text())),
        );

        return in_array('damage', $headers, true) && in_array('wins', $headers, true);
    }

    /**
     * @return list<BrawlStanding>
     */
    private function standingsRows(Crawler $table): array
    {
        $standings = [];

        foreach ($table->filter('tr') as $node) {
            $row = new Crawler($node);
            $cells = $row->filter('td');

            if ($cells->count() < 4) {
                continue;
            }

            $link = $cells->eq(1)->filter('a');

            if ($link->count() === 0) {
                continue;
            }

            $playerId = $this->playerIdFromHref($link->attr('href'));

            if ($playerId === null) {
                continue;
            }

            $standings[$playerId] = new BrawlStanding(
                rank: (int) trim($cells->eq(0)->text()),
                playerId: $playerId,
                name: trim($link->text()),
                wins: $this->intCell($cells->eq(2)->text()),
                damage: $this->intCell($cells->eq(3)->text()),
            );
        }

        return array_values($standings);
    }

    private function intCell(string $text): int
    {
        return (int) str_replace(',', '', trim($text));
    }

    private function listValue(Crawler $crawler, string $label): ?string
    {
        foreach ($crawler->filter('li') as $node) {
            $text = trim(preg_replace('/\s+/', ' ', new Crawler($node)->text()) ?? '');

            if (str_starts_with($text, $label.':')) {
                return trim(substr($text, strlen($label) + 1));
            }
        }

        return null;
    }

    private function intListValue(Crawler $crawler, string $label): ?int
    {
        $value = $this->listValue($crawler, $label);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }
}
