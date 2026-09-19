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
    /**
     * @param  list<int>  $gameCrewIds
     */
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly CrewRosterParser $parser,
        private readonly array $gameCrewIds,
    ) {}

    public static function forCrew(Character $character, int $gameCrewId): self
    {
        return self::forCrews($character, [$gameCrewId]);
    }

    /**
     * @param  list<int>  $gameCrewIds
     */
    public static function forCrews(Character $character, array $gameCrewIds): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(CrewRosterParser::class),
            array_values(array_unique($gameCrewIds)),
        );
    }

    /**
     * Every crew's roster, one after the other in the order the crews were
     * given. One crew_profile.php read per crew; a player listed twice (a
     * roster caught mid-transfer) is only targeted once.
     *
     * @return list<AttackTarget>
     */
    public function targets(): array
    {
        $targets = [];

        foreach ($this->gameCrewIds as $gameCrewId) {
            foreach ($this->rosterTargets($gameCrewId) as $target) {
                $targets[$target->playerId] ??= $target;
            }
        }

        return array_values($targets);
    }

    /**
     * @return list<AttackTarget>
     */
    private function rosterTargets(int $gameCrewId): array
    {
        $roster = $this->parser->parse(
            $this->client->get('crew_profile.php', ['id' => $gameCrewId])->body(),
            $gameCrewId,
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
        return count($this->gameCrewIds) === 1
            ? "crew members (crew {$this->gameCrewIds[0]})"
            : 'crew members (crews '.implode(', ', $this->gameCrewIds).')';
    }
}
