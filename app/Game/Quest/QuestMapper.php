<?php

namespace App\Game\Quest;

use App\Game\Data\QuestDetail;
use App\Game\Http\GameClient;
use App\Game\Parsers\ShowQuestParser;
use App\Models\Quest;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crawls the public show_quest.php?quest={id} catalog by enumerating ids and
 * upserts each quest with its full step list. Unknown ids answer "Quest not
 * found" — normal gaps, counted as missing. No session is used or risked.
 */
class QuestMapper
{
    public function __construct(
        private readonly GameClient $client,
        private readonly ShowQuestParser $parser,
    ) {}

    public static function forServer(int $serverId): self
    {
        return new self(GameClient::forServer($serverId), new ShowQuestParser);
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): bool|null  $shouldStop
     * @return array{mapped: int, missing: int, failed: int}
     */
    public function map(int $fromId, int $toId, ?Closure $log = null, ?Closure $shouldStop = null): array
    {
        $log ??= fn (string $message) => null;
        $mapped = 0;
        $missing = 0;
        $failed = 0;

        for ($id = $fromId; $id <= $toId; $id++) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            try {
                $detail = $this->parser->parse(
                    $this->client->get('show_quest.php', ['quest' => $id])->body(),
                );
            } catch (Throwable $exception) {
                $failed++;
                $log("Quest {$id}: {$exception->getMessage()}");

                continue;
            }

            if ($detail === null) {
                $missing++;

                continue;
            }

            $this->store($detail);
            $mapped++;
            $log(sprintf('Quest %d: %s (%d steps)', $id, $detail->name, count($detail->steps)));
        }

        $linked = $this->linkPrerequisites();

        if ($linked > 0) {
            $log("Linked {$linked} prerequisite quests by name.");
        }

        return ['mapped' => $mapped, 'missing' => $missing, 'failed' => $failed];
    }

    private function store(QuestDetail $detail): void
    {
        DB::transaction(function () use ($detail) {
            $quest = Quest::updateOrCreate(['game_quest_id' => $detail->gameQuestId], [
                'name' => $detail->name,
                'required_level' => $detail->requiredLevel,
                'prerequisite' => $detail->prerequisite,
                'giver' => $detail->giver(),
                'steps_count' => count($detail->steps),
                'total_exp' => $detail->totalExp(),
                'item_rewards' => array_merge(
                    ...array_map(
                        fn ($step) => array_map(fn ($reward) => $reward->toArray(), $step->itemRewards),
                        $detail->steps,
                    ),
                ),
                'last_mapped_at' => now(),
            ]);

            $quest->steps()->delete();

            foreach ($detail->steps as $index => $step) {
                $stored = $quest->steps()->create([
                    'position' => $index + 1,
                    'npc' => $step->npc,
                    'message' => $step->message,
                    'item_rewards' => array_map(fn ($reward) => $reward->toArray(), $step->itemRewards),
                    'exp_reward' => $step->expReward,
                    'reply' => $step->reply,
                ]);

                foreach ($step->conditions as $conditionIndex => $condition) {
                    $stored->conditions()->create([
                        'quest_id' => $quest->id,
                        'position' => $conditionIndex + 1,
                        'type' => $condition->type,
                        'target' => $condition->target,
                        'amount' => $condition->amount,
                    ]);
                }
            }
        });
    }

    /**
     * Resolve prerequisite names to catalog rows. Runs over the whole table
     * each crawl — a quest's prerequisite may only appear in a later id range.
     */
    private function linkPrerequisites(): int
    {
        return DB::update(<<<'SQL'
            UPDATE quests
            SET prerequisite_quest_id = p.id
            FROM quests p
            WHERE quests.prerequisite = p.name
              AND quests.prerequisite_quest_id IS DISTINCT FROM p.id
              AND (SELECT count(*) FROM quests d WHERE d.name = quests.prerequisite) = 1
            SQL);
    }
}
