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
        'npc_name',
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

    public function displayName(): string
    {
        return $this->label ?? "Quest {$this->quest_id}";
    }
}
