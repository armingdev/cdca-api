<?php

namespace App\Models;

use Database\Factories\QuestStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step of a crawled quest. `conditions` is a list of
 * {type: kill|collect, target, amount}; `item_rewards` a list of
 * {name, amount}. Accept/intro steps have empty conditions.
 */
class QuestStep extends Model
{
    /** @use HasFactory<QuestStepFactory> */
    use HasFactory;

    protected $fillable = [
        'quest_id',
        'position',
        'npc',
        'message',
        'item_rewards',
        'exp_reward',
        'reply',
    ];

    protected function casts(): array
    {
        return [
            'item_rewards' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Quest, $this>
     */
    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }

    /**
     * @return HasMany<QuestCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(QuestCondition::class)->orderBy('position');
    }
}
