<?php

namespace App\Game\Combat;

use App\Game\Data\AttackRefusal;
use App\Game\Data\AttackTarget;
use App\Game\Enums\AttackRefusalReason;
use App\Game\Enums\BattleKind;
use App\Game\Enums\BattleOutcome;
use App\Game\Http\GameClient;
use App\Game\Parsers\AttackRefusalParser;
use App\Game\Parsers\BattleResultParser;
use App\Game\Parsers\PlayerSearchParser;
use App\Models\BattleEvent;
use App\Models\Character;

/**
 * Player-vs-player: search by name, then attack via a POST that carries the
 * target's per-render hash. Success is structural — a 302 to /plrattack/{id}/
 * (mirrors the PvE 302 to /attack/{id}/). The result page uses the same JS
 * vars as PvE, so BattleResultParser is reused.
 *
 * A 200 means the attack did not happen; AttackRefusalParser classifies why,
 * and the caller uses that to schedule rather than retry blindly.
 */
class PvpAttackService
{
    /** The last attack's refusal, when it was refused. */
    private ?AttackRefusal $lastRefusal = null;

    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly PlayerSearchParser $searchParser,
        private readonly BattleResultParser $resultParser,
        private readonly AttackRefusalParser $refusalParser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(PlayerSearchParser::class),
            app(BattleResultParser::class),
            app(AttackRefusalParser::class),
        );
    }

    /**
     * Search players by name. `searchType` 0 = begins with, 1 = contains
     * (VERIFIED 2026-08-22 — it selects the match mode, not the field).
     *
     * @return list<AttackTarget>
     */
    public function search(string $name, bool $contains = false): array
    {
        $response = $this->client->post('playersearch.php', [
            'searchType' => $contains ? 1 : 0,
            'search' => $name,
            'submit' => 'search',
        ]);

        return array_map(
            fn ($result): AttackTarget => $result->toAttackTarget(),
            $this->searchParser->parse($response->body()),
        );
    }

    /**
     * The best search match for a name — an exact (case-insensitive) hit if
     * present, otherwise the first result.
     */
    public function findTarget(string $name): ?AttackTarget
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
     * Mint a fresh attack hash for a target that arrived without one (crew
     * rosters and brawl standings render no attack icon).
     */
    public function refreshHash(AttackTarget $target): ?AttackTarget
    {
        foreach ($this->search($target->name) as $result) {
            if ($result->playerId === $target->playerId) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Attack a target that already carries a hash.
     *
     * `rage` is the server-supplied cost from the target's own
     * showAttackWindow call — a hidden field, not a slider (VERIFIED
     * 2026-08-22). Sending our own number would misstate the cost.
     */
    public function attack(AttackTarget $target, string $message = '', string $redirect = 'world'): BattleEvent
    {
        $this->lastRefusal = null;

        $response = $this->client->post('somethingelse.php', [
            'message' => $message,
            'rage' => $target->rageCost ?? 0,
            'hash' => $target->hash,
        ], [
            'attackid' => $target->playerId,
            'r' => $redirect,
        ]);

        $location = (string) $response->header('Location');

        if ($response->status() !== 302 || ! preg_match('~/plrattack/(\d+)~', $location, $m)) {
            return $this->recordRefusal($target, $response->body(), $response->effectiveUri()?->__toString());
        }

        $battleId = (int) $m[1];
        $result = $this->resultParser->parse($this->client->get("plrattack/{$battleId}/")->body());

        return BattleEvent::create([
            'character_id' => $this->character->id,
            'kind' => BattleKind::Pvp,
            'opponent_name' => $target->name,
            'opponent_player_id' => $target->playerId,
            'opponent_level' => $target->level,
            'battle_id' => $battleId,
            'outcome' => $result->outcome,
            'exp_gained' => $result->expGained,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Why the last attack was refused, or null when it went through. Lets the
     * runner reschedule a cooldown precisely instead of assuming a full hour.
     */
    public function lastRefusal(): ?AttackRefusal
    {
        return $this->lastRefusal;
    }

    private function recordRefusal(AttackTarget $target, string $body, ?string $finalUrl): BattleEvent
    {
        $refusal = $this->refusalParser->parse($body, $finalUrl);
        $this->lastRefusal = $refusal;

        return BattleEvent::create([
            'character_id' => $this->character->id,
            'kind' => BattleKind::Pvp,
            'opponent_name' => $target->name,
            'opponent_player_id' => $target->playerId,
            'opponent_level' => $target->level,
            'outcome' => BattleOutcome::Failed,
            'fail_reason' => $this->failReason($refusal),
            'occurred_at' => now(),
        ]);
    }

    private function failReason(AttackRefusal $refusal): string
    {
        if ($refusal->reason === AttackRefusalReason::Cooldown) {
            return "On cooldown — attacked {$refusal->minutesSinceLastAttack}m ago, free in {$refusal->retryInMinutes()}m.";
        }

        return $refusal->message !== '' ? $refusal->message : 'No redirect from the PvP attack.';
    }
}
