<?php

namespace App\Game\Parsers;

use App\Game\Data\AccountCharacter;
use Symfony\Component\DomCrawler\Crawler;

class AccountsPageParser
{
    /**
     * Parse accounts.php?ac_serverid= into character rows. Each row carries a
     * PLAY! link (world.php?suid=&serverid=) plus name/level/crew in colored
     * <font><b> cells.
     *
     * @return list<AccountCharacter>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $characters = [];

        foreach ($crawler->filter('a[href*="world.php?suid="]') as $link) {
            $node = new Crawler($link);
            $query = [];
            parse_str((string) parse_url($node->attr('href') ?? '', PHP_URL_QUERY), $query);

            if (! isset($query['suid'], $query['serverid'])) {
                continue;
            }

            $row = $node->closest('tr') ?? $crawler;

            $characters[] = new AccountCharacter(
                suid: (int) $query['suid'],
                serverId: (int) $query['serverid'],
                name: $this->cell($row, '#FFFF00') ?? '',
                level: ($level = $this->cell($row, '#FFFFFF')) !== null ? (int) $level : null,
                crew: $this->cell($row, '#999999'),
            );
        }

        return $characters;
    }

    private function cell(Crawler $row, string $fontColor): ?string
    {
        $cell = $row->filter(sprintf('font[color="%s"] b', $fontColor));

        if ($cell->count() === 0) {
            return null;
        }

        $text = trim($cell->first()->text());

        return $text === '' ? null : $text;
    }
}
