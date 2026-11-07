<?php

namespace App\Game\Combat;

use App\Game\Data\MobSighting;
use App\Game\Enums\BattleOutcome;
use App\Game\Exceptions\GameServerException;
use App\Game\Http\GameClient;
use App\Game\Parsers\BattleResultParser;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;

/**
 * One PvE attack = GET somethingelse.php with the mob's single-use encid.
 * Success is structural: a 302 to /attack/{battleId}/. Anything else means the
 * attack never happened — a 200 whose body carries the reason (stale encid,
 * contention, out of rage), or a 500 carrying the game's own alert — and the
 * engine re-plans instead of depending on any single sentinel string.
 */
class AttackService
{
    /**
     * The game's alert, served as an HTTP 500, for a mob that was gone by the
     * time the attack landed: killed by someone else between our room load and
     * the click. Contention, not a mob that refuses — MobRunner budgets it
     * separately from refusals.
     */
    public const string TARGET_GONE_ALERT = 'Invalid mob!';

    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly BattleResultParser $parser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character, GameClient::forCharacter($character), app(BattleResultParser::class));
    }

    /**
     * @throws GameServerException when the 5xx is an outage, not the game's answer
     */
    public function attack(MobSighting $sighting): BattleEvent
    {
        try {
            $response = $this->client->get('somethingelse.php', [
                'lightbox' => 1,
                'attackid' => $sighting->encid,
                'r' => 'world',
            ]);
        } catch (GameServerException $exception) {
            $alert = $exception->gameAlert();

            if ($alert === null) {
                throw $exception;
            }

            return $this->recordFailure($sighting, $alert);
        }

        $location = (string) $response->header('Location');

        if ($response->status() !== 302 || ! preg_match('~/attack/(\d+)~', $location, $matches)) {
            return $this->recordFailure($sighting, $response->body());
        }

        $battleId = (int) $matches[1];
        $result = $this->parser->parse($this->client->get("attack/{$battleId}/")->body());

        return BattleEvent::create([
            'character_id' => $this->character->id,
            'mob_id' => $this->resolveMobId($sighting),
            'room_id' => $this->character->current_room_id,
            'battle_id' => $battleId,
            'outcome' => $result->outcome,
            'exp_gained' => $result->expGained,
            'gold_gained' => $result->goldGained,
            'drop_name' => $result->dropName,
            'occurred_at' => now(),
        ]);
    }

    private function recordFailure(MobSighting $sighting, string $body): BattleEvent
    {
        $reason = str(strip_tags($body))->squish()->limit(180)->toString();

        return BattleEvent::create([
            'character_id' => $this->character->id,
            'mob_id' => $this->resolveMobId($sighting),
            'room_id' => $this->character->current_room_id,
            'outcome' => BattleOutcome::Failed,
            'fail_reason' => $reason !== '' ? $reason : 'No redirect and empty response body.',
            'occurred_at' => now(),
        ]);
    }

    private function resolveMobId(MobSighting $sighting): ?int
    {
        return Mob::where('name', $sighting->name)->value('id');
    }
}
