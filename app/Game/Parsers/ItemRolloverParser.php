<?php

namespace App\Game\Parsers;

use App\Game\Data\ItemDetail;
use App\Game\Exceptions\ParseException;

/**
 * Parses an item_rollover.php tooltip (table#itemtable). Works on the text
 * layer (tags stripped) so it survives markup changes: name is the leading
 * text, `[Required Level N]` / `[Slot - X]` are bracket tags, stats are the
 * `+N label` / `+N% label` tokens with an optional enhancement bonus
 * `+N (+M) label` (base and bonus are summed), and the trade limit is the
 * "Can change hands N more time(s) today" line.
 */
class ItemRolloverParser
{
    public function parse(string $body): ItemDetail
    {
        $text = $this->toText($body);

        if ($text === '') {
            throw new ParseException('Empty item_rollover response.');
        }

        return new ItemDetail(
            name: $this->parseName($text),
            slot: preg_match('/\[Slot - ([^\]]+)\]/', $text, $slot) ? trim($slot[1]) : null,
            requiredLevel: preg_match('/\[Required Level (\d+)\]/', $text, $level) ? (int) $level[1] : null,
            stats: $this->parseStats($text),
            tradesLeftToday: preg_match('/Can change hands\s+([\d,]+)\s+more time/is', $text, $trade)
                ? (int) str_replace(',', '', $trade[1])
                : null,
        );
    }

    private function toText(string $body): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $body);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = str_replace("\u{A0}", ' ', $text);

        return trim(preg_replace('/[ \t]+/', ' ', $text));
    }

    /**
     * The name is everything before the first bracket tag, stat bonus, or
     * line break — whichever comes first.
     */
    private function parseName(string $text): string
    {
        $end = strcspn($text, "[+\n");
        $name = trim(substr($text, 0, $end));

        if ($name === '') {
            throw new ParseException('item_rollover response has no item name: '.substr($text, 0, 120));
        }

        return $name;
    }

    /**
     * @return array<string, int>
     */
    private function parseStats(string $text): array
    {
        preg_match_all(
            '/\+([\d,]+)(?:\s*\(\+([\d,]+)\))?%?\s+([A-Za-z][A-Za-z ]*[A-Za-z])/',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        $stats = [];

        foreach ($matches as $match) {
            $label = strtolower(trim(preg_replace('/\s+/', ' ', $match[3])));
            $value = (int) str_replace(',', '', $match[1]) + (int) str_replace(',', '', $match[2] ?? '');

            $stats[$label] = ($stats[$label] ?? 0) + $value;
        }

        return $stats;
    }
}
