<?php

namespace App\Models;

use App\Game\Enums\AttackRefusalReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When a character may next attack a given player.
 *
 * The game allows one attack per target per 60 minutes and refuses the rest
 * with a 200. Tracking it here turns those refusals into skips: the engine
 * only spends requests on targets it believes are free.
 */
class AttackCooldown extends Model
{
    protected $fillable = [
        'character_id',
        'opponent_player_id',
        'opponent_name',
        'last_attacked_at',
        'next_attackable_at',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_attacked_at' => 'datetime',
            'next_attackable_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @param  Builder<AttackCooldown>  $query
     */
    public function scopeBlocking(Builder $query, ?Carbon $at = null): void
    {
        $query->where('next_attackable_at', '>', $at ?? now());
    }

    public function isBlocking(?Carbon $at = null): bool
    {
        return $this->next_attackable_at->isAfter($at ?? now());
    }

    /** Whole minutes until this target frees up (0 when it already has). */
    public function minutesRemaining(?Carbon $at = null): int
    {
        $at ??= now();

        return $this->isBlocking($at) ? (int) ceil($at->diffInMinutes($this->next_attackable_at, true)) : 0;
    }

    /**
     * Record an attack and block the target for the standard window.
     *
     * `$cooldownMinutes` is a parameter rather than a constant because Time
     * Warp (skill 3017, Affliction) lets some characters attack twice in the
     * same hour — a per-character capability, never a global one.
     */
    public static function record(
        int $characterId,
        int $opponentPlayerId,
        ?string $opponentName = null,
        ?CarbonInterface $at = null,
        int $cooldownMinutes = AttackRefusalReason::COOLDOWN_MINUTES,
        string $source = 'observed',
    ): self {
        // Accepts any Carbon flavour: the attack log yields CarbonImmutable,
        // `now()` an Illuminate\Support\Carbon.
        $at = $at === null ? now() : Carbon::instance($at);

        return self::updateOrCreate(
            ['character_id' => $characterId, 'opponent_player_id' => $opponentPlayerId],
            [
                'opponent_name' => $opponentName,
                'last_attacked_at' => $at,
                'next_attackable_at' => $at->copy()->addMinutes($cooldownMinutes),
                'source' => $source,
            ],
        );
    }
}
