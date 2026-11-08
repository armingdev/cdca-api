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
 * an inference from the giver's silence, which the game also uses for a level
 * or a prerequisite not yet met, so it is skippable but disposable: it is
 * never written, and never trusted, for a character below the quest's level
 * (prod held 663 such rows in Nov 2026), it is cleared when the quest's
 * prerequisite is seen to finish, and the clear endpoints remain for the rest.
 */
class QuestProgressLedger
{
    public function recordCompleted(Character $character, Quest $quest, ?int $runId = null): void
    {
        $this->record($character, $quest, QuestProgressStatus::Completed, $runId);

        // The giver was silent about these because this quest was not done
        // yet; now it is, so ask again rather than skip them for good.
        CharacterQuestProgress::query()
            ->where('character_id', $character->id)
            ->where('status', QuestProgressStatus::Unavailable)
            ->whereIn('quest_id', Quest::where('prerequisite_quest_id', $quest->id)->select('id'))
            ->delete();
    }

    public function recordUnavailable(Character $character, Quest $quest, ?int $runId = null): void
    {
        // Below the quest's level the giver's silence is the level talking,
        // which says nothing about whether the quest is done.
        if ($quest->required_level !== null && $character->level < $quest->required_level) {
            return;
        }

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
     * finishing one does not settle it — and neither is an "unavailable" row
     * recorded while the character was below the quest's level.
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
            ->with('quest:id,required_level,repeatable')
            ->where('character_id', $character->id)
            ->whereIn('quest_id', $ids)
            ->get()
            ->filter(fn (CharacterQuestProgress $row): bool => match ($row->status) {
                QuestProgressStatus::Completed => ! $row->quest->repeatable,
                QuestProgressStatus::Unavailable => ! $this->recordedBelowLevel($row),
            })
            ->pluck('quest_id')
            ->values()
            ->all();
    }

    private function recordedBelowLevel(CharacterQuestProgress $row): bool
    {
        $recordedAt = $row->context['level'] ?? null;
        $required = $row->quest->required_level;

        return $recordedAt !== null && $required !== null && (int) $recordedAt < $required;
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
