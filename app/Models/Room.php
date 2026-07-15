<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * Room ids are the game's own ids, never auto-generated.
     */
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'north',
        'east',
        'south',
        'west',
        'doors',
        'is_gated',
        'gate_reason',
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
            'id' => 'integer',
            'north' => 'integer',
            'east' => 'integer',
            'south' => 'integer',
            'west' => 'integer',
            'doors' => 'array',
            'is_gated' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Mob, $this>
     */
    public function mobs(): BelongsToMany
    {
        return $this->belongsToMany(Mob::class)->withPivot('last_seen_at');
    }

    /**
     * Neighbor room ids keyed by direction, exits only.
     *
     * @return array<string, int>
     */
    public function exits(): array
    {
        return array_filter([
            'north' => $this->north,
            'east' => $this->east,
            'south' => $this->south,
            'west' => $this->west,
        ]);
    }
}
