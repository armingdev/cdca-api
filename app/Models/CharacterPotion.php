<?php

namespace App\Models;

use Database\Factories\CharacterPotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A character's stack of one potion and its active window, as last read
 * from the game (see the create_character_potions_table migration).
 */
class CharacterPotion extends Model
{
    /** @use HasFactory<CharacterPotionFactory> */
    use HasFactory;

    /** Back-off after refusals the game gave no reason for: 5 min, 1 h, then a day. */
    public const array REFUSAL_BACKOFF_MINUTES = [5, 60, 1440];

    /**
     * Mirrors the column defaults: a stack created mid-activation is read
     * back before any refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 0,
        'refusals' => 0,
    ];

    protected $fillable = [
        'character_id',
        'potion_id',
        'iid',
        'quantity',
        'active_until',
        'active_synced_at',
        'last_activated_at',
        'refusals',
        'refused_until',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iid' => 'integer',
            'quantity' => 'integer',
            'active_until' => 'datetime',
            'active_synced_at' => 'datetime',
            'last_activated_at' => 'datetime',
            'refusals' => 'integer',
            'refused_until' => 'datetime',
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
     * @return BelongsTo<Potion, $this>
     */
    public function potion(): BelongsTo
    {
        return $this->belongsTo(Potion::class);
    }

    /**
     * `active_until` is the estimate an activation writes (now + the catalog
     * duration) until the next game read replaces it — with the real time
     * left, or with null when the effect is gone.
     */
    public function isActive(): bool
    {
        return $this->active_until?->isFuture() ?? false;
    }

    public function isRefused(): bool
    {
        return $this->refused_until?->isFuture() ?? false;
    }

    public function recordRefusal(): void
    {
        $step = min($this->refusals, count(self::REFUSAL_BACKOFF_MINUTES) - 1);

        $this->update([
            'refusals' => $this->refusals + 1,
            'refused_until' => now()->addMinutes(self::REFUSAL_BACKOFF_MINUTES[$step]),
        ]);
    }
}
