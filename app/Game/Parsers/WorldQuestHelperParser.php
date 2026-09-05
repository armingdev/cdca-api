<?php

namespace App\Game\Parsers;

use App\Game\Data\ActiveQuest;
use App\Game\Data\QuestObjective;
use App\Game\Enums\QuestObjectiveType;
use App\Game\Exceptions\ParseException;

/**
 * Parses the world_questHelper.php tracker (`{"qtable":"<html>"}`) into the
 * character's in-progress quests.
 *
 * Each quest renders as one `<div align="center" class="mb-3">` holding a
 * `<table id="quest-{questId}">`, and every objective row of the step the
 * character currently stands on carries an
 * `onClick="getQuestHelpData2('{questid}','{mobid}','{itemname}','{stepid}','{conditionid}')"`
 * call followed by `<b>{label}</a>[:]</b>{n}/{m}[ killed]`.
 *
 * Row shapes (VERIFIED 2026-09-05, live capture of a 46-quest tracker):
 * - kill — mobid set, itemname empty, `{Mob}: {n}/{m} killed`
 * - collect — mobid 0, itemname set, `{Item}: {n}/{m}`
 * - talk — conditionid 0, mobid set, no counter, `Find {Mob}` (go start the
 *   next step there) or `Return to {Mob}` (turn in; rendered only once every
 *   kill/collect row of the step is complete)
 *
 * A completed row renders green (`#008000`); an outstanding one red
 * (`#EA2300`). Talk rows render black — they are the pending action.
 *
 * The markup is malformed on purpose by the game (`</a>` nested inside `<b>`,
 * unclosed cells, tooltip attributes containing raw `<br>` and `>`), so this
 * parses by anchored pattern rather than by building a DOM.
 */
class WorldQuestHelperParser
{
    /**
     * The tracker row: the helper call, then the coloured label and counter.
     */
    private const string ROW_PATTERN = "/getQuestHelpData2\\('(\\d+)',\\s*'(\\d*)',\\s*'((?:\\\\'|[^'])*)',\\s*'(\\d+)',\\s*'(\\d+)'\\)/";

    private const string LABEL_PATTERN = '/^[^<]*<font[^>]*color="(#[0-9A-Fa-f]{6})"[^>]*>\s*<b>(.*?)<\/a>\s*:?\s*<\/b>([^<]*)/s';

    /** The colour the tracker paints a satisfied objective. */
    private const string COMPLETE_COLOUR = '#008000';

    /**
     * @return list<ActiveQuest>
     */
    public function parse(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! array_key_exists('qtable', $data)) {
            throw new ParseException('Not a questHelper response: '.substr($body, 0, 200));
        }

        $quests = [];

        foreach (explode('<div align="center" class="mb-3">', (string) $data['qtable']) as $block) {
            $quest = $this->parseQuest($block);

            if ($quest !== null) {
                $quests[] = $quest;
            }
        }

        return $quests;
    }

    private function parseQuest(string $block): ?ActiveQuest
    {
        if (! preg_match('/id="quest-(\d+)"/', $block, $idMatch)) {
            return null;
        }

        $objectives = $this->parseObjectives($block, $stepId);

        // A quest with no readable row tells us nothing about where the
        // character stands, and guessing a step id would send the runner to
        // the wrong dialog.
        if ($objectives === []) {
            return null;
        }

        return new ActiveQuest(
            questId: (int) $idMatch[1],
            name: $this->parseName($block),
            stepId: $stepId,
            objectives: $objectives,
        );
    }

    /**
     * The quest title sits between the tracker's collapse/hide icons and the
     * header's closing tag.
     */
    private function parseName(string $block): ?string
    {
        if (! preg_match_all('/<\/svg>\s*([^<]+?)\s*<\/span>/', $block, $matches)) {
            return null;
        }

        $name = trim(html_entity_decode((string) end($matches[1])));

        return $name === '' ? null : $name;
    }

    /**
     * Every objective row of the step, in page order.
     *
     * @param  int|null  $stepId  set to the step the rows belong to
     * @return list<QuestObjective>
     */
    private function parseObjectives(string $block, ?int &$stepId): array
    {
        if (! preg_match_all(self::ROW_PATTERN, $block, $rows, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $stepId = null;

            return [];
        }

        $objectives = [];

        foreach ($rows as $index => $row) {
            // Everything up to the next row's helper call is this row's own
            // markup — the label, its colour and the counter all live there.
            $from = $row[0][1] + strlen($row[0][0]);
            $tail = $index + 1 < count($rows)
                ? substr($block, $from, $rows[$index + 1][0][1] - $from)
                : substr($block, $from);

            $objective = $this->parseRow($row, $tail);

            if ($objective !== null) {
                $stepId ??= (int) $row[4][0];
                $objectives[] = $objective;
            }
        }

        return $objectives;
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $row  the helper call's captures
     */
    private function parseRow(array $row, string $tail): ?QuestObjective
    {
        if (! preg_match(self::LABEL_PATTERN, $tail, $label)) {
            return null;
        }

        $mobId = (int) $row[2][0];
        $itemName = html_entity_decode(str_replace("\\'", "'", $row[3][0]));
        $conditionId = (int) $row[5][0];
        $complete = strcasecmp($label[1], self::COMPLETE_COLOUR) === 0;
        $name = $this->normalize(strip_tags($label[2]));
        $counterText = $this->normalize($label[3]);

        // No counter means the game is asking for a visit, not a body count.
        if (! preg_match('/^(\d[\d,]*)\s*\/\s*(\d[\d,]*)/', $counterText, $counter)) {
            return new QuestObjective(
                type: QuestObjectiveType::Talk,
                target: $this->talkTarget($name),
                current: 0,
                required: 0,
                complete: $complete,
                mobId: $mobId,
                conditionId: $conditionId,
            );
        }

        return new QuestObjective(
            // The game's own discriminator: a collect row names the item and
            // carries no mob, a kill row the reverse.
            type: $itemName !== '' ? QuestObjectiveType::Collect : QuestObjectiveType::Kill,
            target: $itemName !== '' ? $itemName : $name,
            current: (int) str_replace(',', '', $counter[1]),
            required: (int) str_replace(',', '', $counter[2]),
            complete: $complete,
            mobId: $mobId,
            conditionId: $conditionId,
        );
    }

    /**
     * The mob named by a talk row. The tracker phrases it as "Find {Mob}" to
     * start the step's dialog and "Return to {Mob}" to turn it in; anything
     * else is kept whole rather than guessed at.
     */
    private function talkTarget(string $label): string
    {
        return preg_match('/^(?:Find|Return to|Talk to|Speak to)\s+(.+)$/i', $label, $match) === 1
            ? trim($match[1])
            : $label;
    }

    private function normalize(string $text): string
    {
        return trim(html_entity_decode(preg_replace('/\s+/', ' ', $text) ?? ''));
    }
}
