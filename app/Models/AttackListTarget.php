<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of an attack list. Added by name; `player_id` is filled in once
 * a search resolves it, after which the id is authoritative — players rename.
 */
class AttackListTarget extends Model
{
    protected $fillable = ['attack_list_id', 'position', 'name', 'player_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'player_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AttackList, $this>
     */
    public function attackList(): BelongsTo
    {
        return $this->belongsTo(AttackList::class);
    }
}
