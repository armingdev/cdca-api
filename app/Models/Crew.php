<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A crew we track for crew-members mode. `game_crew_id` addresses
 * `crew_profile.php?id=`, which returns any crew's roster.
 */
class Crew extends Model
{
    protected $fillable = [
        'server_id',
        'game_crew_id',
        'name',
        'leader',
        'total_members',
        'average_level',
        'members_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'members_synced_at' => 'datetime',
            'total_members' => 'integer',
            'average_level' => 'integer',
        ];
    }

    /**
     * @return HasMany<PlayerCharacter, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(PlayerCharacter::class);
    }
}
