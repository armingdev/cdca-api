<?php

namespace App\Models;

use Database\Factories\MobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Mob extends Model
{
    /** @use HasFactory<MobFactory> */
    use HasFactory;

    protected $fillable = [
        'game_mob_id',
        'name',
        'level',
        'rage_cost',
        'type',
        'can_form',
        'image',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'game_mob_id' => 'integer',
            'level' => 'integer',
            'rage_cost' => 'integer',
            'type' => 'integer',
            'can_form' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Room, $this>
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)->withPivot('last_seen_at');
    }
}
