<?php

namespace App\Game\Auth;

use App\Game\Http\GameClient;
use App\Game\Parsers\AccountsPageParser;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Collection;

/**
 * Discovers all characters on an RGA (up to 75 across both servers) via
 * accounts.php?ac_serverid= and upserts them.
 */
class CharacterSyncService
{
    public function __construct(private readonly AccountsPageParser $parser) {}

    /**
     * @return Collection<int, Character>
     */
    public function sync(Rga $rga): Collection
    {
        $characters = collect();

        foreach (array_keys(config('outwar.servers')) as $serverId) {
            $response = GameClient::forRga($rga, $serverId)
                ->get('accounts.php', ['ac_serverid' => $serverId]);

            foreach ($this->parser->parse($response->body()) as $row) {
                $characters->push(Character::updateOrCreate(
                    ['server_id' => $row->serverId, 'suid' => $row->suid],
                    [
                        'rga_id' => $rga->id,
                        'name' => $row->name,
                        'level' => $row->level,
                        'crew' => $row->crew,
                    ],
                ));
            }
        }

        return $characters;
    }
}
