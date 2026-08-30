<?php

namespace App\Game\Data;

/**
 * A parsed mob_talk.php step. The single authoritative "can I advance?" signal
 * is the presence of the finish link — the game only renders it when the
 * step's objective (if any) is satisfied, or immediately for objective-less
 * accept/intro steps.
 */
final readonly class QuestStepPage
{
    /**
     * @param  list<QuestObjective>  $objectives
     * @param  list<string>  $continueLinks  every non-finish mob_talk link, in page order
     * @param  list<string>  $rewards
     */
    public function __construct(
        public ?string $npcName,
        public ?string $questTitle,
        public string $dialog,
        public array $objectives,
        public ?string $finishLink,
        public array $continueLinks,
        public ?int $npcId,
        public ?int $stepId,
        public array $rewards,
        public ?int $expReward,
    ) {}

    /**
     * The game shows the finish link only when the step can be completed now.
     */
    public function canAdvance(): bool
    {
        return $this->finishLink !== null;
    }

    public function hasObjectives(): bool
    {
        return $this->objectives !== [];
    }

    /**
     * @return list<QuestObjective>
     */
    public function unmetObjectives(): array
    {
        return array_values(array_filter($this->objectives, fn (QuestObjective $o) => ! $o->complete));
    }

    /**
     * The first continue link, for callers that do not care which quest it
     * belongs to. Prefer continueLinkFor() when running a specific quest.
     */
    public function continueLink(): ?string
    {
        return $this->continueLinks[0] ?? null;
    }

    /**
     * The continue link belonging to a given quest, when the page names one.
     * An NPC offering several quests renders a link each; following the wrong
     * one silently abandons the quest in hand.
     */
    public function continueLinkFor(int $questId): ?string
    {
        foreach ($this->continueLinks as $href) {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

            if (isset($query['questid']) && (int) $query['questid'] === $questId) {
                return $href;
            }
        }

        return null;
    }
}
