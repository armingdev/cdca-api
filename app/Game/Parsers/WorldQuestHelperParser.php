<?php

namespace App\Game\Parsers;

use App\Game\Data\QuestHelperToggle;
use App\Game\Exceptions\ParseException;

/**
 * Parses the world_questHelper.php tracker (`{"qtable":"<html>"}`) into its
 * "find my target" toggles: every objective line carries an
 * onClick="getQuestHelpData2('{questid}', '{mobid}', '{itemname}',
 * '{stepid}', '{conditionid}')" call.
 */
class WorldQuestHelperParser
{
    /**
     * @return list<QuestHelperToggle>
     */
    public function parse(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! array_key_exists('qtable', $data)) {
            throw new ParseException('Not a questHelper response: '.substr($body, 0, 200));
        }

        preg_match_all(
            "/getQuestHelpData2\\('(\\d+)',\\s*'(\\d*)',\\s*'((?:\\\\'|[^'])*)',\\s*'(\\d+)',\\s*'(\\d+)'\\)/",
            (string) $data['qtable'],
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $m) => new QuestHelperToggle(
            questId: (int) $m[1],
            mobId: (int) $m[2],
            itemName: html_entity_decode(str_replace("\\'", "'", $m[3])),
            stepId: (int) $m[4],
            conditionId: (int) $m[5],
        ), $matches);
    }
}
