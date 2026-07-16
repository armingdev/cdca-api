<?php

namespace App\Models;

use Database\Factories\QuestListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestList extends Model
{
    /** @use HasFactory<QuestListFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * @return HasMany<QuestListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuestListItem::class)->orderBy('position');
    }

    /**
     * Append a quest to the end of the list. (Not named `append` — that
     * collides with Eloquent's appended-attributes method.)
     */
    public function addQuest(int $questId, string $npcName, ?string $label = null): QuestListItem
    {
        return $this->items()->create([
            'position' => (int) $this->items()->max('position') + 1,
            'quest_id' => $questId,
            'npc_name' => $npcName,
            'label' => $label,
        ]);
    }

    /**
     * Remove the item at a position and close the gap.
     */
    public function removePosition(int $position): bool
    {
        $removed = $this->items()->where('position', $position)->delete();

        if ($removed === 0) {
            return false;
        }

        $this->items()->where('position', '>', $position)->decrement('position');

        return true;
    }
}
