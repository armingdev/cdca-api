<?php

namespace App\Game\Combat\Targets;

use App\Game\Data\AttackTarget;
use App\Game\Http\GameClient;
use App\Game\Parsers\CrewRosterParser;
use App\Models\Character;
use App\Models\Crew;
use App\Models\PlayerCharacter;

/**
 * Targets from a crew's roster (`crew_profile.php?id={crewId}`), which serves
 * any crew — ours or a rival's.
 *
 * Unlike the hitlists, roster rows render no attack icon, so these targets
 * arrive without a hash and the runner must mint one per target before
 * attacking. That makes this the most expensive source per target; the
 * cooldown filter runs first precisely so those searches are not wasted.
 */
class CrewMembersTargetSource implements PvpTargetSource
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly CrewRosterParser $parser,
        private readonly int $gameCrewId,
    ) {}

    public static function forCrew(Character $character, int $gameCrewId): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(CrewRosterParser::class),
            $gameCrewId,
        );
    }

    /**
     * @return list<AttackTarget>
     */
    public function targets(): array
    {
        $roster = $this->parser->parse(
            $this->client->get('crew_profile.php', ['id' => $this->gameCrewId])->body(),
            $this->gameCrewId,
        );

        $crew = Crew::updateOrCreate(
            ['server_id' => $this->character->server_id, 'game_crew_id' => $roster->crewId],
            [
                'name' => $roster->name,
                'leader' => $roster->leader,
                'total_members' => $roster->totalMembers,
                'average_level' => $roster->averageLevel,
                'members_synced_at' => now(),
            ],
        );

        $targets = [];

        foreach ($roster->members as $member) {
            $target = $member->toAttackTarget();

            PlayerCharacter::remember($this->character->server_id, $target, $crew->id);

            $targets[] = $target;
        }

        return $targets;
    }

    public function label(): string
    {
        return "crew members (crew {$this->gameCrewId})";
    }
}
