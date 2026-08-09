<?php

namespace App\Game\Parsers;

use App\Game\Data\EquipmentSet;
use App\Game\Data\EquippedItem;

/**
 * Parses equipment.php — the paper-doll fragment. Every worn item is one
 * <img> whose handlers carry all three facts we need:
 *
 *   onclick="removeItem('{iid}',{suid},0);document.getElementById('slot{N}').innerHTML=''"
 *   alt="{Item Name}"
 *
 * An empty slot renders as an empty div with no img and no slot marker, so a
 * slot is "empty" precisely by being absent from the parsed set.
 */
class EquipmentPageParser
{
    public function parse(string $body): EquipmentSet
    {
        preg_match_all('/<img[^>]*>/i', $body, $tags);

        $slots = [];

        foreach ($tags[0] as $tag) {
            if (! preg_match("/removeItem\('(\d+)'/", $tag, $iid)) {
                continue;
            }

            if (! preg_match("/getElementById\('slot(\d+)'\)/", $tag, $slot)) {
                continue;
            }

            preg_match('/alt="([^"]*)"/', $tag, $name);

            $slotId = (int) $slot[1];

            $slots[$slotId][] = new EquippedItem(
                iid: (int) $iid[1],
                name: html_entity_decode($name[1] ?? '', ENT_QUOTES | ENT_HTML5),
                slotId: $slotId,
            );
        }

        ksort($slots);

        return new EquipmentSet($slots);
    }
}
