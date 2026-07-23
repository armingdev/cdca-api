<?php

namespace App\Models;

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
        'buff_until',
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
            'buff_until' => 'datetime',
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
        if ($this->buff_until !== null) {
            return $this->buff_until->isFuture();
        }

        $duration = $this->current_duration_minutes ?? $this->skill->duration_minutes;

        return $this->last_cast_at !== null
            && $duration !== null
            && $this->last_cast_at->addMinutes($duration)->isFuture();
    }

    /**
     * A server-read recharge window (recharge_until, from skills_info.php)
     * wins over the computed last_cast_at + cooldown fallback. Server readings
     * are cleared on cast, so a non-null value is always current.
     */
    public function isOnCooldown(): bool
    {
        if ($this->recharge_until !== null) {
            return $this->recharge_until->isFuture();
        }

        $cooldown = $this->current_cooldown_minutes ?? $this->skill->cooldown_minutes;

        return $this->last_cast_at !== null
            && $cooldown !== null
            && $this->last_cast_at->addMinutes($cooldown)->isFuture();
    }
}
