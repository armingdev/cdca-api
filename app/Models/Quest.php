<?php

namespace App\Models;

use Database\Factories\QuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A game quest as crawled from the public show_quest.php?quest={id} page.
 * `game_quest_id` is the game's own id (identical on sigil and torax);
 * `giver` is the NPC of the first step.
 */
class Quest extends Model
{
    /** @use HasFactory<QuestFactory> */
    use HasFactory;

    protected $fillable = [
        'game_quest_id',
        'name',
        'required_level',
        'prerequisite',
        'prerequisite_quest_id',
        'giver',
        'steps_count',
        'total_exp',
        'item_rewards',
        'last_mapped_at',
    ];

    protected function casts(): array
    {
        return [
            'item_rewards' => 'array',
            'last_mapped_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<QuestStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(QuestStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<QuestCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(QuestCondition::class);
    }

    /**
     * @return BelongsTo<Quest, $this>
     */
    public function prerequisiteQuest(): BelongsTo
    {
        return $this->belongsTo(Quest::class, 'prerequisite_quest_id');
    }
}
