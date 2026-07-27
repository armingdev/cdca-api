<?php

namespace App\Models;

use Database\Factories\QuestItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A collect-quest item and the mobs known to drop it (seeded from the
 * data/xowh-seed QuestItems catalog). Mobs are stored by name because the
 * seed predates our mob ids; resolve against the mobs table at query time.
 */
class QuestItem extends Model
{
    /** @use HasFactory<QuestItemFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'source_mobs',
        'target_room_id',
        'helper_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'source_mobs' => 'array',
            'helper_verified_at' => 'datetime',
        ];
    }

    /**
     * The game's designated farm room, learned by following the quest-helper
     * compass during a run.
     *
     * @return BelongsTo<Room, $this>
     */
    public function targetRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'target_room_id');
    }
}
