<?php

namespace App\Models;

use Database\Factories\AttackListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-authored PvP target list — the attack-list mode's source.
 *
 * Mirrors QuestList/QuestListItem, including its position handling, so the
 * two list features behave identically for the client.
 */
class AttackList extends Model
{
    /** @use HasFactory<AttackListFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'server_id', 'name'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AttackListTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(AttackListTarget::class)->orderBy('position');
    }

    /**
     * Append a target by name — what the user knows. The player id is
     * resolved on first search and cached back onto the row.
     */
    public function addTarget(string $name, ?int $playerId = null): AttackListTarget
    {
        return $this->targets()->create([
            'position' => (int) $this->targets()->max('position') + 1,
            'name' => $name,
            'player_id' => $playerId,
        ]);
    }

    /**
     * Remove the target at a position and close the gap.
     */
    public function removePosition(int $position): bool
    {
        $removed = $this->targets()->where('position', $position)->delete();

        if ($removed === 0) {
            return false;
        }

        $this->targets()->where('position', '>', $position)->decrement('position');

        return true;
    }
}
