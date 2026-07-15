<?php

namespace App\Game\Combat;

use App\Game\Data\MobSighting;
use App\Game\Enums\BattleOutcome;
use App\Game\Http\GameClient;
use App\Game\Parsers\BattleResultParser;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;

/**
 * One PvE attack = GET somethingelse.php with the mob's single-use encid.
 * Success is structural: a 302 to /attack/{battleId}/. A 200 with no
 * redirect means the attack never happened — the body carries the reason
 * (stale encid, contention, out of rage) and the engine re-plans instead of
 * depending on any single sentinel string.
 */
class AttackService
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly BattleResultParser $parser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character, GameClient::forCharacter($character), app(BattleResultParser::class));
    }

    public function attack(MobSighting $sighting): BattleEvent
    {
        $response = $this->client->get('somethingelse.php', [
            'lightbox' => 1,
            'attackid' => $sighting->encid,
            'r' => 'world',
        ]);

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
