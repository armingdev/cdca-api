<?php

namespace App\Models;

use App\Game\Enums\BrawlType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A Brawl round, refreshed from /closedpvp. Runs pin their `start_at` to a
 * round's window rather than to a wall-clock guess, because the fortnightly
 * cadence is the game's to define.
 */
class BrawlRound extends Model
{
    protected $fillable = [
        'server_id',
        'type',
        'round_id',
        'starts_at',
        'ends_at',
        'participant_count',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BrawlType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<BrawlRound>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now())->orderBy('starts_at');
    }

    /** Whether the attack window is open right now. */
    public function isOpen(): bool
    {
        return $this->starts_at !== null
            && $this->ends_at !== null
            && now()->between($this->starts_at, $this->ends_at);
    }
}
