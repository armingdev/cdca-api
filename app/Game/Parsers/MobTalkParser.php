<?php

namespace App\Game\Parsers;

use App\Game\Data\QuestObjective;
use App\Game\Data\QuestStepPage;
use App\Game\Enums\QuestObjectiveType;
use App\Game\Exceptions\ParseException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses a mob_talk.php quest-step page. Objectives render as
 * `<div class="quest-objective complete|incomplete">…<strong>Name:</strong>
 * n/m killed</div>`; the `&finish=1` action link appears only when the step
 * can be completed; rewards render as `<font color=green>…</font>`.
 */
class MobTalkParser
{
    public function parse(string $html): QuestStepPage
    {
        if (! str_contains($html, 'mob-dialog-container') && ! str_contains($html, 'mob_talk.php')) {
            throw new ParseException('Not a mob_talk step page: '.substr(strip_tags($html), 0, 200));
        }

        $crawler = new Crawler($html);

        $finishLink = $this->hrefMatching($crawler, 'a[href*="mob_talk.php"][href*="finish=1"]');
        $continueLinks = $this->continueLinks($crawler, $finishLink);
        $reference = $finishLink ?? ($continueLinks[0] ?? null);

        return new QuestStepPage(
            npcName: $this->text($crawler, 'h2.mob-name'),
            questTitle: $this->text($crawler, 'span.badge'),
            dialog: $this->text($crawler, 'p.mob-description') ?? '',
            objectives: $this->parseObjectives($crawler),
            finishLink: $finishLink,
            continueLinks: $continueLinks,
            npcId: $this->queryInt($reference ?? $this->hrefMatching($crawler, 'a[href*="mob.php"]'), 'id'),
            stepId: $this->queryInt($reference, 'stepid'),
            rewards: $this->parseRewards($crawler),
            expReward: $this->parseExpReward($crawler),
        );
    }

    /**
     * Every `.quest-objective` row the step renders, in page order.
     *
     * A row without an `n/m` counter is a talk/turn-in line, not a parse
     * failure. Returning null for it used to leave holes in this list, and the
     * typed consumer downstream then died with a TypeError that no
     * GameException handler catches — killing the whole run over a mixed step.
     *
     * @return list<QuestObjective>
     */
    private function parseObjectives(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('.quest-objective')->each(function (Crawler $node): ?QuestObjective {
                $text = preg_replace('/\s+/', ' ', trim($node->text()));

                if ($text === '') {
                    return null;
                }

                $complete = $this->isComplete($node);

                if (! preg_match('/^(.+?):\s*(\d+)\s*\/\s*(\d+)(\s+killed)?/i', $text, $m)) {
                    return new QuestObjective(
                        type: QuestObjectiveType::Talk,
                        target: trim(explode(':', $text)[0]),
                        current: 0,
                        required: 0,
                        complete: $complete,
                    );
                }

                return new QuestObjective(
                    type: isset($m[4]) && trim($m[4]) !== '' ? QuestObjectiveType::Kill : QuestObjectiveType::Collect,
                    target: trim($m[1]),
                    current: (int) $m[2],
                    required: (int) $m[3],
                    complete: $complete,
                );
            }),
        ));
    }

    private function isComplete(Crawler $node): bool
    {
        $class = (string) $node->attr('class');

        return str_contains($class, 'complete') && ! str_contains($class, 'incomplete');
    }

    /**
     * @return list<string>
     */
    private function parseRewards(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('font[color="green"]')->each(fn (Crawler $n) => trim(preg_replace('/\s+/', ' ', $n->text()))),
            fn (string $reward) => str_contains($reward, 'received'),
        ));
    }

    private function parseExpReward(Crawler $crawler): ?int
    {
        foreach ($this->parseRewards($crawler) as $reward) {
            if (preg_match('/([\d,]+)\s+experience/i', $reward, $m)) {
                return (int) str_replace(',', '', $m[1]);
            }
        }

        return null;
    }

    /**
     * Every mob_talk link that is not the finish link — "continue to next
     * step" actions, in page order.
     *
     * All of them, not just the first: an NPC that offers several quests
     * renders a link per quest, and blindly following the first one walks the
     * runner into a different quest's step and abandons the one in hand. The
     * caller picks the link that belongs to the quest it is running.
     *
     * @return list<string>
     */
    private function continueLinks(Crawler $crawler, ?string $finishLink): array
    {
        $links = [];

        foreach ($crawler->filter('a[href*="mob_talk.php"]') as $node) {
            $href = html_entity_decode((string) new Crawler($node)->attr('href'));

            if (! str_contains($href, 'finish=1') && $href !== $finishLink) {
                $links[] = $href;
            }
        }

        return array_values(array_unique($links));
    }

    private function hrefMatching(Crawler $crawler, string $selector): ?string
    {
        $matches = $crawler->filter($selector);

        return $matches->count() > 0 ? html_entity_decode((string) $matches->first()->attr('href')) : null;
    }

    private function text(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        return $node->count() > 0 ? trim(preg_replace('/\s+/', ' ', $node->first()->text())) : null;
    }

    private function queryInt(?string $url, string $param): ?int
    {
        if ($url === null) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query[$param]) ? (int) $query[$param] : null;
    }
}
