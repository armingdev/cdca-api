<?php

namespace App\Game\Parsers;

use App\Game\Data\QuestCondition;
use App\Game\Data\QuestDetail;
use App\Game\Data\QuestItemReward;
use App\Game\Data\QuestStepDetail;
use App\Game\Enums\QuestObjectiveType;
use App\Game\Exceptions\ParseException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses a show_quest.php?quest={id} page (public, no session needed).
 * Header: `<div id="quest">` with `<h1>{Name} ({id})</h1>` and italic
 * "Required Level:" / "Prerequisite:" lines. Each `<div class="step">` has a
 * "Talk To:" NPC, a "Message:" prompt, an optional Conditions list
 * ("Kill: 50 X" / "Collect: 1 Y") and a Rewards list mixing the NPC's reply,
 * "You have received {n} experience!" and "You have received {n} {item}!".
 * Unknown ids answer a bare "Quest not found" string, unreleased ids
 * "Quest under development" — both → null.
 */
class ShowQuestParser
{
    public function parse(string $html): ?QuestDetail
    {
        if (str_contains($html, 'Quest not found') || str_contains($html, 'Quest under development')) {
            return null;
        }

        if (! str_contains($html, 'id="quest"')) {
            throw new ParseException('Not a show_quest page: '.substr(strip_tags($html), 0, 200));
        }

        $crawler = new Crawler($html);

        $heading = $this->normalize($crawler->filter('#quest h1')->text());

        if (! preg_match('/^(.*)\((\d+)\)$/', $heading, $m)) {
            throw new ParseException("Unrecognized quest heading: {$heading}");
        }

        return new QuestDetail(
            gameQuestId: (int) $m[2],
            name: trim($m[1]),
            requiredLevel: $this->headerInt($crawler, 'Required Level:'),
            prerequisite: $this->headerPrerequisite($crawler),
            steps: $crawler->filter('div.step')->each(fn (Crawler $step) => $this->parseStep($step)),
        );
    }

    private function parseStep(Crawler $step): QuestStepDetail
    {
        $expReward = null;
        $itemRewards = [];
        $replies = [];

        foreach ($this->listLinesAfter($step, 'Rewards:') as $line) {
            if (preg_match('/received\s+([\d,]+)\s+experience!/i', $line, $m)) {
                $expReward = (int) str_replace(',', '', $m[1]);
            } elseif (preg_match('/received\s+([\d,]+)\s+(.+?)!$/i', $line, $m)) {
                $itemRewards[] = new QuestItemReward(name: trim($m[2]), amount: (int) str_replace(',', '', $m[1]));
            } else {
                $replies[] = $line;
            }
        }

        return new QuestStepDetail(
            npc: trim(str_replace('Talk To:', '', $this->labeledText($step, 'Talk To:'))),
            message: trim(str_replace('Message:', '', $this->labeledText($step, 'Message:'))),
            conditions: $this->parseConditions($step),
            itemRewards: $itemRewards,
            expReward: $expReward,
            reply: $replies === [] ? null : implode("\n", $replies),
        );
    }

    /**
     * @return list<QuestCondition>
     */
    private function parseConditions(Crawler $step): array
    {
        $conditions = [];

        foreach ($this->listLinesAfter($step, 'Conditions:') as $line) {
            if (preg_match('/^(Kill|Collect):\s*([\d,]+)\s+(.+)$/i', $line, $m)) {
                $conditions[] = new QuestCondition(
                    type: strcasecmp($m[1], 'Kill') === 0 ? QuestObjectiveType::Kill : QuestObjectiveType::Collect,
                    target: trim($m[3]),
                    amount: (int) str_replace(',', '', $m[2]),
                );
            }
        }

        return $conditions;
    }

    /**
     * Text of each <li> in the <ul> that directly follows the given
     * `<p><strong>{label}</strong></p>` heading inside a step.
     *
     * @return list<string>
     */
    private function listLinesAfter(Crawler $step, string $label): array
    {
        $list = $step->filterXPath(sprintf(
            './/p[strong[contains(text(), "%s")]]/following-sibling::ul[1]',
            $label,
        ));

        if ($list->count() === 0) {
            return [];
        }

        return $list->first()->filter('li')->each(fn (Crawler $li) => $this->normalize($li->text()));
    }

    private function labeledText(Crawler $step, string $label): string
    {
        $node = $step->filterXPath(sprintf('.//p[strong[contains(text(), "%s")]]', $label));

        return $node->count() > 0 ? $this->normalize($node->first()->text()) : '';
    }

    private function headerInt(Crawler $crawler, string $label): ?int
    {
        foreach ($crawler->filter('#quest p') as $node) {
            $text = $this->normalize(new Crawler($node)->text());

            if (str_starts_with($text, $label) && preg_match('/(\d+)/', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function headerPrerequisite(Crawler $crawler): ?string
    {
        foreach ($crawler->filter('#quest p') as $node) {
            $text = $this->normalize(new Crawler($node)->text());

            if (str_starts_with($text, 'Prerequisite:')) {
                $value = trim(substr($text, strlen('Prerequisite:')));

                return $value === '' || strcasecmp($value, 'None') === 0 ? null : $value;
            }
        }

        return null;
    }

    /**
     * Collapse whitespace and strip the literal <br> markers the game leaves
     * as encoded entities inside dialog text.
     */
    private function normalize(string $text): string
    {
        return trim(preg_replace(['/<br\s*\/?>/i', '/\s+/'], [' ', ' '], $text));
    }
}
