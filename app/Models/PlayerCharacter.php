<?php

namespace App\Models;

use App\Game\Data\AttackTarget;
use App\Game\Enums\TargetAttackability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another player's character — a PvP target we have seen on some list.
 *
 * `player_id` is the game's id (`profile.php?id=`), the same value our own
 * characters carry as `suid`.
 */
class PlayerCharacter extends Model
{
    protected $fillable = [
        'server_id',
        'player_id',
        'name',
        'level',
        'crew_id',
        'attackability',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attackability' => TargetAttackability::class,
            'last_seen_at' => 'datetime',
            'level' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Crew, $this>
     */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    /**
     * Remember a target seen on any list. Only overwrites fields the sighting
     * actually carried — a crew roster knows the level but no attackability,
     * a hitlist knows both — so a richer earlier sighting is never downgraded
     * to null by a thinner later one.
     */
    public static function remember(int $serverId, AttackTarget $target, ?int $crewId = null): self
    {
        $attributes = array_filter([
            'name' => $target->name,
            'level' => $target->level,
            'crew_id' => $crewId,
            'attackability' => $target->attackability === TargetAttackability::Unknown
                ? null
                : $target->attackability->value,
        ], fn ($value) => $value !== null);

        return self::updateOrCreate(
            ['server_id' => $serverId, 'player_id' => $target->playerId],
            [...$attributes, 'last_seen_at' => now()],
        );
    }
}
