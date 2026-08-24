<?php

namespace App\Game\Parsers;

use App\Game\Data\CrewMember;
use App\Game\Data\CrewRoster;
use App\Game\Parsers\Concerns\ParsesAttackWindows;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses `crew_profile.php?id={crewId}` — the one endpoint that returns a
 * roster for *any* crew, ours or a rival's.
 *
 *   Rank | Name | Level
 *
 * Rows carry no attack icon, so members come back without a hash and
 * crew-members mode must mint one per target via playersearch. (`/crew_home`
 * also lists members but ignores its id parameter — own crew only.)
 *
 * Fixture: crew_profile_members.html.
 */
class CrewRosterParser
{
    use ParsesAttackWindows;

    public function parse(string $html, int $crewId): CrewRoster
    {
        $crawler = new Crawler($html);

        return new CrewRoster(
            crewId: $crewId,
            name: $this->crewName($crawler),
            members: $this->members($crawler),
            leader: $this->listValue($crawler, 'Leader'),
            totalMembers: $this->intListValue($crawler, 'Total Members'),
            averageLevel: $this->intListValue($crawler, 'Average Level'),
            allyCrews: $this->linkedCrews($crawler, 'CREW ALLIES'),
            enemyCrews: $this->linkedCrews($crawler, 'CREW ENEMIES'),
        );
    }

    private function crewName(Crawler $crawler): string
    {
        $heading = $crawler->filter('h2');

        return $heading->count() > 0 ? trim($heading->first()->text()) : '';
    }

    /**
     * @return list<CrewMember>
     */
    private function members(Crawler $crawler): array
    {
        $members = [];

        foreach ($crawler->filter('tr') as $node) {
            $row = new Crawler($node);
            $cells = $row->filter('td');

            // Roster rows are exactly Rank | Name(link) | Level.
            if ($cells->count() < 3) {
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

            $level = trim($cells->eq(2)->text());

            $members[$playerId] = new CrewMember(
                playerId: $playerId,
                name: trim($link->text()),
                level: is_numeric($level) ? (int) $level : null,
                rank: trim($cells->eq(0)->text()),
            );
        }

        return array_values($members);
    }

    private function listValue(Crawler $crawler, string $label): ?string
    {
        foreach ($crawler->filter('li') as $node) {
            $item = new Crawler($node);
            $text = trim(preg_replace('/\s+/', ' ', $item->text()) ?? '');

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

    /**
     * Ally/enemy crews sit in cards keyed by their title.
     *
     * @return array<int, string>
     */
    private function linkedCrews(Crawler $crawler, string $title): array
    {
        $crews = [];

        foreach ($crawler->filter('.card') as $node) {
            $card = new Crawler($node);
            $heading = $card->filter('.card-title');

            if ($heading->count() === 0 || trim($heading->text()) !== $title) {
                continue;
            }

            foreach ($card->filter('a[href]') as $link) {
                $anchor = new Crawler($link);

                if (preg_match('/crew_profile(?:\.php)?\?id=(\d+)/', (string) $anchor->attr('href'), $m)) {
                    $crews[(int) $m[1]] = trim($anchor->text());
                }
            }
        }

        return $crews;
    }
}
