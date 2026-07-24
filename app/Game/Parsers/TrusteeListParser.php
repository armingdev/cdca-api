<?php

namespace App\Game\Parsers;

use App\Game\Data\TrusteeListEntry;
use App\Game\Exceptions\ParseException;

/**
 * Parses ajax/trusteeList.php?dropdown=1 — select2 JSON with two groups:
 * "My Characters" (the RGA's own, current server) and "Trustees"
 * (characters from other RGAs shared with this one). The `--Change Server--`
 * pseudo-entry (id 0) is skipped.
 */
class TrusteeListParser
{
    /**
     * @return list<TrusteeListEntry>
     */
    public function parse(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['results'])) {
            throw new ParseException('trusteeList response is not the expected JSON: '.substr($body, 0, 200));
        }

        $entries = [];

        foreach ($data['results'] as $group) {
            $isTrustee = ($group['text'] ?? '') === 'Trustees';

            foreach ($group['children'] ?? [] as $child) {
                $suid = (int) ($child['id'] ?? 0);

                if ($suid === 0) {
                    continue;
                }

                $entries[] = new TrusteeListEntry(
                    suid: $suid,
                    name: (string) $child['text'],
                    isTrustee: $isTrustee,
                );
            }
        }

        return $entries;
    }
}
