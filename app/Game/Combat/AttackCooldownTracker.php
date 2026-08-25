<?php

namespace App\Game\Combat;

use App\Game\Data\AttackRefusal;
use App\Game\Data\AttackTarget;
use App\Game\Enums\AttackRefusalReason;
use App\Game\Http\GameClient;
use App\Game\Parsers\AttackLogParser;
use App\Models\AttackCooldown;
use App\Models\Character;

/**
 * Tracks the game's one-attack-per-target-per-60-minutes rule.
 *
 * The engine's whole PvP economy rests on this: without it a recurring run
 * spends its entire pass collecting refusals. With it, targets on cooldown
 * are skipped before a request is spent, and a refusal that slips through
 * corrects the record using the elapsed minutes the game states.
 */
class AttackCooldownTracker
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly AttackLogParser $logParser,
        private readonly int $cooldownMinutes = AttackRefusalReason::COOLDOWN_MINUTES,
    ) {}

    public static function forCharacter(Character $character, ?int $cooldownMinutes = null): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(AttackLogParser::class),
            $cooldownMinutes ?? AttackRefusalReason::COOLDOWN_MINUTES,
        );
    }

    /**
     * Rebuild cooldowns from the game's own attack log.
     *
     * Local state does not survive a restart, a second client, or an attack
     * made by hand in the browser — the log does. Called once per run start.
     *
     * @return int cooldowns still blocking after the sync
     */
    public function syncFromAttackLog(): int
    {
        $entries = $this->logParser->parse(
            $this->client->get('attacklog', ['mode' => 'out'])->body()
        );

        $blocking = 0;

        foreach ($entries as $entry) {
            $freeAt = $entry->occurredAt->addMinutes($this->cooldownMinutes);

            if ($freeAt->isPast()) {
                continue;
            }

            AttackCooldown::record(
                characterId: $this->character->id,
                opponentPlayerId: $entry->opponentPlayerId,
                opponentName: $entry->opponentName,
                at: $entry->occurredAt->toMutable(),
                cooldownMinutes: $this->cooldownMinutes,
                source: 'attack-log',
            );

            $blocking++;
        }

        return $blocking;
    }

    /**
     * The subset of targets this character may attack right now, in the order
     * given. One query, not one per target.
     *
     * @param  list<AttackTarget>  $targets
     * @return list<AttackTarget>
     */
    public function attackable(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $blocked = $this->blockedIds(array_map(fn (AttackTarget $t): int => $t->playerId, $targets));

        return array_values(array_filter(
            $targets,
            fn (AttackTarget $target): bool => ! isset($blocked[$target->playerId])
                && $target->attackability->isWorthAttacking(),
        ));
    }

    /**
     * Player ids currently on cooldown, as a lookup.
     *
     * @param  list<int>  $playerIds
     * @return array<int, true>
     */
    public function blockedIds(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        return AttackCooldown::query()
            ->where('character_id', $this->character->id)
            ->whereIn('opponent_player_id', $playerIds)
            ->blocking()
            ->pluck('opponent_player_id')
            ->flip()
            ->map(fn (): bool => true)
            ->all();
    }

    /** Note a successful attack, blocking the target for the window. */
    public function recordAttack(AttackTarget $target): AttackCooldown
    {
        return AttackCooldown::record(
            characterId: $this->character->id,
            opponentPlayerId: $target->playerId,
            opponentName: $target->name,
            cooldownMinutes: $this->cooldownMinutes,
        );
    }

    /**
     * Correct the record from a refusal, so the next pass does not spend a
     * request on a target the game has already told us it will not accept.
     *
     * A cooldown refusal names how long ago we hit the target, so we learn the
     * exact free-at time rather than guessing a fresh full window. Structural
     * refusals (an ally, PvP immunity) carry their own block window instead —
     * see AttackRefusalReason::blockMinutes(). A refusal we cannot classify
     * blocks nothing: we have no idea when it would succeed.
     */
    public function recordRefusal(AttackTarget $target, AttackRefusal $refusal): ?AttackCooldown
    {
        $blockMinutes = $refusal->reason->blockMinutes();

        if ($blockMinutes === null) {
            return null;
        }

        if ($refusal->reason === AttackRefusalReason::Cooldown) {
            if ($refusal->minutesSinceLastAttack === null) {
                return null;
            }

            return AttackCooldown::record(
                characterId: $this->character->id,
                opponentPlayerId: $target->playerId,
                opponentName: $target->name,
                at: now()->subMinutes($refusal->minutesSinceLastAttack),
                cooldownMinutes: $this->cooldownMinutes,
                source: 'refusal',
            );
        }

        return AttackCooldown::record(
            characterId: $this->character->id,
            opponentPlayerId: $target->playerId,
            opponentName: $target->name,
            cooldownMinutes: $blockMinutes,
            source: $refusal->reason->value,
        );
    }

    /**
     * When the earliest blocked target frees up — what a runner reports when
     * a pass finds nothing to attack.
     */
    public function nextFreeInMinutes(): ?int
    {
        $next = AttackCooldown::query()
            ->where('character_id', $this->character->id)
            ->blocking()
            ->min('next_attackable_at');

        return $next === null ? null : max(1, (int) ceil(now()->diffInMinutes($next, true)));
    }
}
