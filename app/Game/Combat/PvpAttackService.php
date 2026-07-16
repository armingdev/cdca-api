<?php

namespace App\Game\Combat;

use App\Game\Data\PlayerSearchResult;
use App\Game\Enums\BattleKind;
use App\Game\Enums\BattleOutcome;
use App\Game\Http\GameClient;
use App\Game\Parsers\BattleResultParser;
use App\Game\Parsers\PlayerSearchParser;
use App\Models\BattleEvent;
use App\Models\Character;

/**
 * Player-vs-player: search by name, then attack via a POST that carries the
 * target's per-render hash. Success is structural — a 302 to /plrattack/{id}/
 * (mirrors the PvE 302 to /attack/{id}/). The result page uses the same JS
 * vars as PvE, so BattleResultParser is reused.
 */
class PvpAttackService
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly PlayerSearchParser $searchParser,
        private readonly BattleResultParser $resultParser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(PlayerSearchParser::class),
            app(BattleResultParser::class),
        );
    }

    /**
     * Search players by name.
     *
     * @return list<PlayerSearchResult>
     */
    public function search(string $name): array
    {
        $response = $this->client->post('playersearch.php', [
            'searchType' => 0,
            'search' => $name,
            'submit' => 'search',
        ]);

        return $this->searchParser->parse($response->body());
    }

    /**
     * The best search match for a name — an exact (case-insensitive) hit if
     * present, otherwise the first result.
     */
    public function findTarget(string $name): ?PlayerSearchResult
    {
        $results = $this->search($name);

        foreach ($results as $result) {
            if (strcasecmp($result->name, $name) === 0) {
                return $result;
            }
        }

        return $results[0] ?? null;
    }

    /**
     * Attack a scouted target. `rage` is the PvP power slider (2–50).
     */
    public function attack(PlayerSearchResult $target, int $rage = 50, string $message = ''): BattleEvent
    {
        $response = $this->client->post('somethingelse.php', [
            'message' => $message,
            'rage' => max(2, min(50, $rage)),
            'hash' => $target->hash,
        ], [
            'attackid' => $target->playerId,
            'r' => 'world',
        ]);

        $location = (string) $response->header('Location');

        if ($response->status() !== 302 || ! preg_match('~/plrattack/(\d+)~', $location, $m)) {
            return $this->recordFailure($target, $response->body());
        }

        $battleId = (int) $m[1];
        $result = $this->resultParser->parse($this->client->get("plrattack/{$battleId}/")->body());

        return BattleEvent::create([
            'character_id' => $this->character->id,
            'kind' => BattleKind::Pvp,
            'opponent_name' => $target->name,
            'battle_id' => $battleId,
            'outcome' => $result->outcome,
            'exp_gained' => $result->expGained,
            'occurred_at' => now(),
        ]);
    }

    private function recordFailure(PlayerSearchResult $target, string $body): BattleEvent
    {
        $reason = str(strip_tags($body))->squish()->limit(180)->toString();

        return BattleEvent::create([
            'character_id' => $this->character->id,
            'kind' => BattleKind::Pvp,
            'opponent_name' => $target->name,
            'outcome' => BattleOutcome::Failed,
            'fail_reason' => $reason !== '' ? $reason : 'No redirect from the PvP attack.',
            'occurred_at' => now(),
        ]);
    }
}
