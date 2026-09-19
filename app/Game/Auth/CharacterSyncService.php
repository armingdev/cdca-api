<?php

namespace App\Game\Auth;

use App\Game\Exceptions\ParseException;
use App\Game\Http\GameClient;
use App\Game\Parsers\AccountsPageParser;
use App\Game\Parsers\TrusteeListParser;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Collection;

/**
 * Discovers all characters on an RGA (up to 75 across both servers) via
 * accounts.php?ac_serverid= and upserts them.
 *
 * accounts.php also lists trustees — characters another RGA shared with this
 * one — without marking them, so each server's ajax/trusteeList.php is read
 * alongside it to tell the two apart.
 */
class CharacterSyncService
{
    public function __construct(
        private readonly AccountsPageParser $parser,
        private readonly TrusteeListParser $trustees,
    ) {}

    /**
     * @return Collection<int, Character>
     */
    public function sync(Rga $rga): Collection
    {
        $characters = collect();

        foreach (array_keys(config('outwar.servers')) as $serverId) {
            $client = GameClient::forRga($rga, $serverId);

            $rows = $this->parser->parse($client->get('accounts.php', ['ac_serverid' => $serverId])->body());

            // Nothing to tell apart on a server the RGA has no characters on.
            if ($rows === []) {
                continue;
            }

            $trusteeSuids = $this->trusteeSuids($client);

            $known = Character::where('server_id', $serverId)
                ->whereIn('suid', array_map(fn ($row) => $row->suid, $rows))
                ->get()
                ->keyBy('suid');

            foreach ($rows as $row) {
                $isTrustee = $trusteeSuids === null ? null : in_array($row->suid, $trusteeSuids, true);
                $existing = $known->get($row->suid);

                // The character's own RGA is connected too and drives it with
                // full control; a trustee grant must not take it away from it.
                if ($isTrustee === true && $existing !== null && $existing->rga_id !== $rga->id && ! $existing->is_trustee) {
                    continue;
                }

                $characters->push(Character::updateOrCreate(
                    ['server_id' => $row->serverId, 'suid' => $row->suid],
                    [
                        'rga_id' => $rga->id,
                        'name' => $row->name,
                        'level' => $row->level,
                        'crew' => $row->crew,
                        // An unreadable trustee list leaves the last known flag alone.
                        ...($isTrustee === null ? [] : ['is_trustee' => $isTrustee]),
                    ],
                ));
            }
        }

        return $characters;
    }

    /**
     * The suids this RGA holds only as a trustee on one server, or null when
     * the list could not be read — the roster itself is still worth syncing.
     *
     * @return list<int>|null
     */
    private function trusteeSuids(GameClient $client): ?array
    {
        try {
            $entries = $this->trustees->parse($client->get('ajax/trusteeList.php', ['dropdown' => 1])->body());
        } catch (ParseException) {
            return null;
        }

        return array_values(array_map(
            fn ($entry) => $entry->suid,
            array_filter($entries, fn ($entry) => $entry->isTrustee),
        ));
    }
}
