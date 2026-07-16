<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterSkill extends Model
{
    protected $fillable = [
        'character_id',
        'skill_id',
        'cast_on_start',
        'last_cast_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skill_id' => 'integer',
            'cast_on_start' => 'boolean',
            'last_cast_at' => 'datetime',
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
     * The buff is active until last_cast_at + the skill's duration.
     */
    public function isBuffActive(): bool
    {
        return $this->last_cast_at !== null
            && $this->skill->duration_minutes !== null
            && $this->last_cast_at->addMinutes($this->skill->duration_minutes)->isFuture();
    }

    /**
     * The skill is on cooldown until last_cast_at + the skill's cooldown.
     */
    public function isOnCooldown(): bool
    {
        return $this->last_cast_at !== null
            && $this->skill->cooldown_minutes !== null
            && $this->last_cast_at->addMinutes($this->skill->cooldown_minutes)->isFuture();
    }
}
