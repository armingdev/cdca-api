<?php

namespace App\Http\Resources;

use App\Models\Quest;
use App\Models\QuestCondition;
use App\Models\QuestStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full quest page: header + ordered steps, every condition enriched with the
 * resolved world locations from QuestLocationResolver (giver rooms, kill-mob
 * rooms, collect-item sources).
 *
 * @mixin Quest
 */
class QuestDetailResource extends JsonResource
{
    /** @var array{giver: list<array<string, mixed>>, mobs: array<string, mixed>, items: array<string, mixed>} */
    private array $locations = ['giver' => [], 'mobs' => [], 'items' => []];

    /**
     * @param  array{giver: list<array<string, mixed>>, mobs: array<string, mixed>, items: array<string, mixed>}  $locations
     */
    public function withLocations(array $locations): self
    {
        $this->locations = $locations;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...QuestResource::make($this->resource)->toArray($request),
            'prerequisite_quest' => $this->whenLoaded(
                'prerequisiteQuest',
                fn () => $this->prerequisiteQuest === null ? null : [
                    'id' => $this->prerequisiteQuest->id,
                    'game_quest_id' => $this->prerequisiteQuest->game_quest_id,
                    'name' => $this->prerequisiteQuest->name,
                    'required_level' => $this->prerequisiteQuest->required_level,
                ],
            ),
            'giver_locations' => $this->locations['giver'],
            'steps' => $this->steps->map(fn (QuestStep $step) => [
                'position' => $step->position,
                'npc' => $step->npc,
                'message' => $step->message,
                'reply' => $step->reply,
                'exp_reward' => $step->exp_reward,
                'item_rewards' => $step->item_rewards ?? [],
                'conditions' => $step->conditions->map(fn (QuestCondition $condition) => [
                    'position' => $condition->position,
                    'type' => $condition->type->value,
                    'target' => $condition->target,
                    'amount' => $condition->amount,
                    ...match ($condition->type->value) {
                        'kill' => ['mob' => $this->locations['mobs'][$condition->target] ?? null],
                        'collect' => ['sources' => $this->locations['items'][$condition->target] ?? null],
                        default => [],
                    },
                ])->values(),
            ])->values(),
        ];
    }
}
