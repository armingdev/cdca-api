<?php

namespace App\Game\Combat\Targets;

use App\Game\Data\AttackTarget;
use App\Game\Http\GameClient;
use App\Game\Parsers\HitlistParser;
use App\Models\Character;
use App\Models\PlayerCharacter;

/**
 * Targets from a hitlist page — the crew hitlist (`/crew_hitlist`) or the
 * character's personal one (`/myhitlist`).
 *
 * The cheapest source by far: the crew list returned 404 targets in a single
 * request, each already carrying its own attack hash and the game's own
 * level-range verdict, so a whole run's worth of targets costs one GET.
 */
class HitlistTargetSource implements PvpTargetSource
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly HitlistParser $parser,
        private readonly bool $crew = true,
    ) {}

    public static function crew(Character $character): self
    {
        return new self($character, GameClient::forCharacter($character), app(HitlistParser::class), true);
    }

    public static function personal(Character $character): self
    {
        return new self($character, GameClient::forCharacter($character), app(HitlistParser::class), false);
    }

    /**
     * @return list<AttackTarget>
     */
    public function targets(): array
    {
        $entries = $this->parser->parse($this->client->get($this->path())->body());

        $targets = [];

        foreach ($entries as $entry) {
            // Hitlists are the richest sighting we get — id, name, level and
            // the level-colour verdict — so feed the registry from them.
            PlayerCharacter::remember($this->character->server_id, $entry->target);

            $targets[] = $entry->target;
        }

        return $targets;
    }

    public function label(): string
    {
        return $this->crew ? 'crew hitlist' : 'personal hitlist';
    }

    private function path(): string
    {
        return $this->crew ? 'crew_hitlist' : 'myhitlist';
    }
}
