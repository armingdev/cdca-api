<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterSkill extends Model
{
    protected $fillable = [
        'character_id',
        'skill_id',
        'trained_level',
        'bonus_level',
        'current_rage_cost',
        'current_cooldown_minutes',
        'current_duration_minutes',
        'cast_on_start',
        'last_cast_at',
        'recharge_until',
        'recharge_synced_at',
        'buff_until',
        'buff_synced_at',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skill_id' => 'integer',
            'trained_level' => 'integer',
            'bonus_level' => 'integer',
            'current_rage_cost' => 'integer',
            'current_cooldown_minutes' => 'integer',
            'current_duration_minutes' => 'integer',
            'cast_on_start' => 'boolean',
            'last_cast_at' => 'datetime',
            'recharge_until' => 'datetime',
            'recharge_synced_at' => 'datetime',
            'buff_until' => 'datetime',
            'buff_synced_at' => 'datetime',
            'synced_at' => 'datetime',
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
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * Effective level as the game displays it: trained + gear/item bonus.
     */
    public function effectiveLevel(): int
    {
        return $this->trained_level + $this->bonus_level;
    }

    /**
     * Only a trained skill can be cast — bonus levels alone (e.g. "(0+8)")
     * do not make a skill usable.
     */
    public function isCastable(): bool
    {
        return $this->trained_level >= 1;
    }

    /**
     * A server-read buff window (buff_until, from the Current Effects panel)
     * wins over the computed last_cast_at + duration fallback. Server readings
     * are cleared on cast, so a non-null value is always current.
     */
    public function isBuffActive(): bool
    {
        $endsAt = $this->buffEndsAt();

        return $endsAt !== null && $endsAt->isFuture();
    }

    /**
     * When the current buff window closes: the server-read value when known,
     * otherwise last cast + the (level-scaled, else catalog) duration. Null
     * when the skill was never cast and no window was read.
     *
     * A sync that saw no entry for this skill in the Current Effects panel
     * clears buff_until and stamps buff_synced_at. That reading is the truth
     * for everything cast before it — without this check the estimate wins,
     * and since plenty of skills last longer than they recharge (Empower:
     * 180 min of buff, 120 min of cooldown), a skill the game says is *not*
     * active still reads as "already active" and never gets re-cast.
     */
    public function buffEndsAt(): ?CarbonInterface
    {
        if ($this->buff_until !== null) {
            return $this->buff_until;
        }

        if ($this->serverReadingSupersedesLastCast($this->buff_synced_at)) {
            return null;
        }

        $duration = $this->current_duration_minutes ?? $this->skill->duration_minutes;

        if ($this->last_cast_at === null || $duration === null) {
            return null;
        }

        return $this->last_cast_at->addMinutes($duration);
    }

    /**
     * A server-read recharge window (recharge_until, from skills_info.php)
     * wins over the computed last_cast_at + cooldown fallback. Server readings
     * are cleared on cast, so a non-null value is always current.
     */
    public function isOnCooldown(): bool
    {
        $endsAt = $this->cooldownEndsAt();

        return $endsAt !== null && $endsAt->isFuture();
    }

    /**
     * When the current cooldown ends: the server-read recharge window when
     * known, otherwise last cast + the (level-scaled, else catalog) cooldown.
     * Null when the skill was never cast and no recharge was read.
     *
     * As with the buff window, a skills_info.php read that carried no
     * "recharging" notice means the skill is ready *now*; the local estimate
     * only applies to casts made after that reading.
     */
    public function cooldownEndsAt(): ?CarbonInterface
    {
        if ($this->recharge_until !== null) {
            return $this->recharge_until;
        }

        if ($this->serverReadingSupersedesLastCast($this->recharge_synced_at)) {
            return null;
        }

        $cooldown = $this->current_cooldown_minutes ?? $this->skill->cooldown_minutes;

        if ($this->last_cast_at === null || $cooldown === null) {
            return null;
        }

        return $this->last_cast_at->addMinutes($cooldown);
    }

    /**
     * Whether a server reading taken at $syncedAt is newer than our last cast
     * and therefore describes the current state better than the estimate.
     */
    private function serverReadingSupersedesLastCast(?CarbonInterface $syncedAt): bool
    {
        if ($syncedAt === null) {
            return false;
        }

        return $this->last_cast_at === null || $this->last_cast_at->lessThan($syncedAt);
    }
}
