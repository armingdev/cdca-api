<?php

namespace App\Models;

use App\Game\Enums\QuestProgressStatus;
use Database\Factories\CharacterQuestProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterQuestProgress extends Model
{
    /** @use HasFactory<CharacterQuestProgressFactory> */
    use HasFactory;

    protected $table = 'character_quest_progress';

    /** recorded_at is the only timestamp. */
    public $timestamps = false;

    protected $fillable = [
        'character_id',
        'quest_id',
        'status',
        'run_id',
        'recorded_at',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuestProgressStatus::class,
            'context' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @return BelongsTo<Quest, $this>
     */
    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
