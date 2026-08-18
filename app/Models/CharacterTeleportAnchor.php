<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teleport anchor as held by one character: the item instance id to send in
 * `itemids[]`, and whether the character can still use it (items can be
 * consumed by other means, skills can be untrained/reset).
 */
class CharacterTeleportAnchor extends Model
{
    protected $fillable = [
        'character_id',
        'teleport_anchor_id',
        'iid',
        'is_available',
        'last_used_at',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iid' => 'integer',
            'is_available' => 'boolean',
            'last_used_at' => 'datetime',
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
     * @return BelongsTo<TeleportAnchor, $this>
     */
    public function anchor(): BelongsTo
    {
        return $this->belongsTo(TeleportAnchor::class, 'teleport_anchor_id');
    }
}
