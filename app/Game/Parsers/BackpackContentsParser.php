<?php

namespace App\Game\Parsers;

use App\Game\Data\BackpackContents;
use App\Game\Data\BackpackItem;

/**
 * Parses an ajax/backpackcontents.php tab. Each item is an <img> carrying
 * `data-iid`, `data-name`, `data-itemidqty` and the context-menu wiring:
 *
 *   makemenu(this,event,100,'{flags}','','{slotIndex}','{iid}','{ownerId}', '{equipSlotType}')
 *
 * flags (e.g. `edzcvs`) select the menu's actions — `e` = equippable.
 * The capacity footer is `<span id="backpackmaxval" data-maxval data-isover
 * data-curitemct>` (maxval -1 = uncapped).
 */
class BackpackContentsParser
{
    public function parse(string $body): BackpackContents
    {
        preg_match_all('/<img[^>]+data-iid="\d+"[^>]*>/', $body, $tags);

        $items = [];

        foreach ($tags[0] as $tag) {
            if (! preg_match('/data-iid="(\d+)"/', $tag, $iid)) {
                continue;
            }

            if (! preg_match(
                "/makemenu\\(this,\\s*event,\\s*\\d+,\\s*'(\\w+)',\\s*'[^']*',\\s*'(\\d+)',\\s*'(\\d+)',\\s*'(\\d+)',\\s*'(\\d+)'\\)/",
                $tag,
                $menu,
            )) {
                continue;
            }

            preg_match('/data-name="([^"]*)"/', $tag, $name);
            preg_match('/data-itemidqty="(\d+)"/', $tag, $qty);
            preg_match('/src="([^"]+)"/', $tag, $src);

            $items[] = new BackpackItem(
                iid: (int) $iid[1],
                name: html_entity_decode($name[1] ?? '', ENT_QUOTES | ENT_HTML5),
                qty: (int) ($qty[1] ?? 1),
                slotIndex: (int) $menu[2],
                ownerId: (int) $menu[4],
                menuFlags: $menu[1],
                equipSlotType: (int) $menu[5],
                image: $src[1] ?? '',
            );
        }

        [$maxSlots, $itemCount, $isOver] = $this->parseCapacity($body);

        return new BackpackContents(
            items: $items,
            maxSlots: $maxSlots,
            itemCount: $itemCount,
            isOver: $isOver,
        );
    }

    /**
     * @return array{int, int, bool}
     */
    private function parseCapacity(string $body): array
    {
        if (! preg_match('/<span id="backpackmaxval"[^>]*>/s', $body, $span)) {
            return [-1, 0, false];
        }

        preg_match('/data-maxval="(-?\d+)"/', $span[0], $max);
        preg_match('/data-curitemct="(\d+)"/', $span[0], $count);
        preg_match('/data-isover="([^"]*)"/', $span[0], $over);

        return [
            (int) ($max[1] ?? -1),
            (int) ($count[1] ?? 0),
            ($over[1] ?? '') !== '',
        ];
    }
}
