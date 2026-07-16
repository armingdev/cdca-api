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
        $continueLink = $this->firstContinueLink($crawler, $finishLink);
        $reference = $finishLink ?? $continueLink;

        return new QuestStepPage(
            npcName: $this->text($crawler, 'h2.mob-name'),
            questTitle: $this->text($crawler, 'span.badge'),
            dialog: $this->text($crawler, 'p.mob-description') ?? '',
            objectives: $this->parseObjectives($crawler),
            finishLink: $finishLink,
            continueLink: $continueLink,
            npcId: $this->queryInt($reference ?? $this->hrefMatching($crawler, 'a[href*="mob.php"]'), 'id'),
            stepId: $this->queryInt($reference, 'stepid'),
            rewards: $this->parseRewards($crawler),
            expReward: $this->parseExpReward($crawler),
        );
    }

    /**
     * @return list<QuestObjective>
     */
    private function parseObjectives(Crawler $crawler): array
    {
        return $crawler->filter('.quest-objective')->each(function (Crawler $node): ?QuestObjective {
            $text = preg_replace('/\s+/', ' ', trim($node->text()));

            if (! preg_match('/^(.+?):\s*(\d+)\s*\/\s*(\d+)(\s+killed)?/i', $text, $m)) {
                return null;
            }

            $class = (string) $node->attr('class');

            return new QuestObjective(
                type: isset($m[4]) && trim($m[4]) !== '' ? QuestObjectiveType::Kill : QuestObjectiveType::Collect,
                target: trim($m[1]),
                current: (int) $m[2],
                required: (int) $m[3],
                complete: str_contains($class, 'complete') && ! str_contains($class, 'incomplete'),
            );
        });
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
     * A mob_talk link that is not the finish link — a "Continue to next step"
     * action (higher stepid, no finish=1).
     */
    private function firstContinueLink(Crawler $crawler, ?string $finishLink): ?string
    {
        foreach ($crawler->filter('a[href*="mob_talk.php"]') as $node) {
            $href = html_entity_decode((string) new Crawler($node)->attr('href'));

            if (! str_contains($href, 'finish=1') && $href !== $finishLink) {
                return $href;
            }
        }

        return null;
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
