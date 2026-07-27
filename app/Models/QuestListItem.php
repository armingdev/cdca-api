<?php

namespace App\Models;

use Database\Factories\QuestListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestListItem extends Model
{
    /** @use HasFactory<QuestListItemFactory> */
    use HasFactory;

    protected $fillable = [
        'quest_list_id',
        'position',
        'quest_id',
        'label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quest_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QuestList, $this>
     */
    public function questList(): BelongsTo
    {
        return $this->belongsTo(QuestList::class);
    }

    /**
     * @return BelongsTo<Quest, $this>
     */
    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }

    public function displayName(): string
    {
        return $this->label ?? $this->quest->name;
    }
}
