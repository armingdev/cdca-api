<?php

namespace App\Game\Parsers;

use App\Game\Data\AvailableQuest;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses an NPC quest-giver popup (mob.php) into its "Available Quests" list.
 * Each entry is a mob_talk.php link carrying the quest's questid + first
 * stepid, e.g. mob_talk.php?id=59293&stepid=3071&userspawn=&questid=672.
 */
class NpcPopupParser
{
    /**
     * @return list<AvailableQuest>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $quests = [];

        foreach ($crawler->filter('a[href*="mob_talk.php"][href*="questid="]') as $node) {
            $link = new Crawler($node);
            $href = html_entity_decode((string) $link->attr('href'));
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

            if (! isset($query['questid'], $query['stepid'], $query['id'])) {
                continue;
            }

            $name = trim(preg_replace('/\s+/', ' ', $link->text()));

            $quests[(int) $query['questid']] = new AvailableQuest(
                questId: (int) $query['questid'],
                firstStepId: (int) $query['stepid'],
                npcId: (int) $query['id'],
                name: $name !== '' ? $name : null,
            );
        }

        return array_values($quests);
    }
}
