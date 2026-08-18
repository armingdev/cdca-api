<?php

namespace App\Models;

use App\Game\Enums\TeleportKind;
use Database\Factories\TeleportAnchorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One known teleport source, shared across characters. `room_id` stays null
 * until a jump is actually observed — an item rollover names an area, not a
 * room, and the prose regularly disagrees with the room name (Key to
 * Industrial District lands in "Cross Roads").
 */
class TeleportAnchor extends Model
{
    /** @use HasFactory<TeleportAnchorFactory> */
    use HasFactory;

    protected $fillable = [
        'kind',
        'game_item_id',
        'skill_id',
        'name',
        'room_id',
        'required_level',
        'rage_cost',
        'cooldown_minutes',
        'description',
        'source',
        'first_seen_at',
        'last_verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TeleportKind::class,
            'game_item_id' => 'integer',
            'skill_id' => 'integer',
            'room_id' => 'integer',
            'required_level' => 'integer',
            'rage_cost' => 'integer',
            'cooldown_minutes' => 'integer',
            'first_seen_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<CharacterTeleportAnchor, $this>
     */
    public function characterAnchors(): HasMany
    {
        return $this->hasMany(CharacterTeleportAnchor::class);
    }

    /**
     * Usable for planning only once we know where it drops you.
     */
    public function hasKnownDestination(): bool
    {
        return $this->room_id !== null;
    }

    public function isFree(): bool
    {
        return $this->rage_cost === 0 && $this->cooldown_minutes === 0;
    }
}
