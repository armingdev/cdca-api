<?php

namespace App\Game\Quest;

use App\Game\Enums\QuestProgressStatus;
use App\Models\Character;
use App\Models\CharacterQuestProgress;
use App\Models\Quest;
use Illuminate\Support\Collection;

/**
 * Remembers, per character, which quests the game has already settled — so a
 * 200-quest list does not re-walk to every completed giver on every start.
 *
 * The two verdicts differ in how much they are trusted. A completion is our
 * own observation and holds unless the quest is repeatable. "Unavailable" is
 * an inference from the giver's silence, which the game also uses for
 * prerequisites not yet met, so it is skippable but disposable: the clear
 * endpoints exist precisely for the character who has since qualified.
 */
class QuestProgressLedger
{
    public function recordCompleted(Character $character, Quest $quest, ?int $runId = null): void
    {
        $this->record($character, $quest, QuestProgressStatus::Completed, $runId);
    }

    public function recordUnavailable(Character $character, Quest $quest, ?int $runId = null): void
    {
        // Never demote a quest we watched finish: the giver goes quiet for a
        // completed quest too, and that silence must not overwrite the truth.
        $existing = CharacterQuestProgress::where('character_id', $character->id)
            ->where('quest_id', $quest->id)
            ->first();

        if ($existing?->status === QuestProgressStatus::Completed) {
            return;
        }

        $this->record($character, $quest, QuestProgressStatus::Unavailable, $runId);
    }

    /**
     * Which of the given quests this character need not walk to.
     *
     * Repeatable quests are never filtered — the whole point of them is that
     * finishing one does not settle it.
     *
     * @param  Collection<int, int>|list<int>  $questIds
     * @return list<int>
     */
    public function skippableQuestIds(Character $character, Collection|array $questIds): array
    {
        $ids = collect($questIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return CharacterQuestProgress::query()
            ->where('character_id', $character->id)
            ->whereIn('quest_id', $ids)
            ->where(function ($query) {
                $query->where('status', QuestProgressStatus::Unavailable)
                    ->orWhere(fn ($inner) => $inner
                        ->where('status', QuestProgressStatus::Completed)
                        ->whereHas('quest', fn ($quest) => $quest->where('repeatable', false)));
            })
            ->pluck('quest_id')
            ->all();
    }

    private function record(Character $character, Quest $quest, QuestProgressStatus $status, ?int $runId): void
    {
        CharacterQuestProgress::updateOrCreate(
            ['character_id' => $character->id, 'quest_id' => $quest->id],
            [
                'status' => $status,
                'run_id' => $runId,
                'recorded_at' => now(),
                // The level at the time explains a stale "unavailable" later.
                'context' => ['level' => $character->level],
            ],
        );
    }
}
