<?php

namespace App\Game\Parsers;

use App\Game\Data\TeleportDestination;

/**
 * Parses the destination dropdown out of skills_info.php?id=27 (the Teleport
 * skill's detail fragment, loaded by the skill row's loadskill(27)):
 *
 *   <form method="post">
 *     <select name="dest"><option value="{roomId}">{name}</option>…</select>
 *     <input type="hidden" name="castskillid" value="27">
 *
 * The select exists nowhere else — the cast_skills page itself has no `dest`.
 * The list is per character (level/quest gated), so it is read per character
 * and never cached globally.
 */
class TeleportDestinationsParser
{
    /**
     * @return list<TeleportDestination>
     */
    public function parse(string $body): array
    {
        if (! preg_match('/<select[^>]+name="dest"[^>]*>(.*?)<\/select>/is', $body, $select)) {
            return [];
        }

        preg_match_all('/<option[^>]+value="(\d+)"[^>]*>(.*?)<\/option>/is', $select[1], $options, PREG_SET_ORDER);

        $destinations = [];

        foreach ($options as $option) {
            $roomId = (int) $option[1];

            if ($roomId === 0) {
                continue;
            }

            $destinations[] = new TeleportDestination(
                roomId: $roomId,
                name: html_entity_decode(trim(strip_tags($option[2])), ENT_QUOTES | ENT_HTML5),
            );
        }

        return $destinations;
    }
}
