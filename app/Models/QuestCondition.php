<?php

namespace App\Models;

use App\Game\Enums\QuestObjectiveType;
use Database\Factories\QuestConditionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One condition of a quest step ("Kill: 50 Sickly Aequora"). `target` is a
 * mob name (kill) or item name (collect) — name-keyed on purpose: names are
 * the only stable bridge between the crawl, the seed and the live world.
 * `quest_id` is denormalized for direct quest→conditions queries.
 */
class QuestCondition extends Model
{
    /** @use HasFactory<QuestConditionFactory> */
    use HasFactory;

    protected $fillable = [
        'quest_step_id',
        'quest_id',
        'position',
        'type',
        'target',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'type' => QuestObjectiveType::class,
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QuestStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(QuestStep::class, 'quest_step_id');
    }

    /**
     * @return BelongsTo<Quest, $this>
     */
    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }
}
