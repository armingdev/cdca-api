<?php

namespace App\Game\Quest;

use App\Game\Data\QuestListImportResult;
use App\Game\Exceptions\GameException;
use App\Models\Quest;
use App\Models\QuestList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a quest list from a file someone already has, instead of adding two
 * hundred quests by hand. Two shapes are understood, told apart by content —
 * the file extension means nothing (.dql and .txt are both in the wild):
 *
 * - a dDCT quest list: JSON `{"Name": "75 Caverns", "QuestNames": ["…", …]}`
 * - plain text: one quest per line, by name or by the game's quest id; blank
 *   lines and lines starting with # are ignored.
 *
 * Quests resolve against our catalog. A line that matches nothing is reported
 * back rather than failing the import — one renamed quest should not cost the
 * other hundred.
 */
class QuestListImporter
{
    /** More lines than any real route has; a bound on what one request can insert. */
    public const int MAX_QUESTS = 1000;

    /**
     * @param  string|null  $name  the name the user asked for; wins over everything
     * @param  string|null  $fallbackName  used when neither the user nor the file names the list (e.g. the file's name)
     *
     * @throws GameException when the content holds no usable quest list
     */
    public function import(User $user, string $content, ?string $name = null, ?string $fallbackName = null): QuestListImportResult
    {
        [$embeddedName, $lines] = $this->read($content);

        $name = trim((string) ($name ?? $embeddedName ?? $fallbackName));

        if ($name === '') {
            throw new GameException('The list needs a name.');
        }

        if ($lines === []) {
            throw new GameException('The file does not contain any quests.');
        }

        if (count($lines) > self::MAX_QUESTS) {
            throw new GameException('A list can hold at most '.self::MAX_QUESTS.' quests.');
        }

        [$questIds, $unmatched, $ambiguous] = $this->resolve($lines);

        if ($questIds === []) {
            throw new GameException('None of the '.count($lines).' quests in the file are in the catalog.');
        }

        $questList = DB::transaction(function () use ($user, $name, $questIds): QuestList {
            $questList = $user->questLists()->create(['name' => QuestList::availableNameFor($user, $name)]);

            $questList->items()->insert(array_map(fn (int $questId, int $index): array => [
                'quest_list_id' => $questList->id,
                'position' => $index + 1,
                'quest_id' => $questId,
                'created_at' => now(),
                'updated_at' => now(),
            ], $questIds, array_keys($questIds)));

            return $questList;
        });

        return new QuestListImportResult($questList, $unmatched, $ambiguous);
    }

    /**
     * @return array{0: string|null, 1: list<string>} the list's own name (dDCT only) and its quest lines, in order
     */
    private function read(string $content): array
    {
        // Windows tools like dDCT write a UTF-8 byte-order mark.
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);

        if (str_starts_with($content, '{')) {
            $data = json_decode($content, true);

            if (! is_array($data) || ! is_array($data['QuestNames'] ?? null)) {
                throw new GameException('This looks like a dDCT quest list, but it has no "QuestNames" to read.');
            }

            return [
                is_string($data['Name'] ?? null) ? $data['Name'] : null,
                $this->clean(array_filter($data['QuestNames'], is_scalar(...))),
            ];
        }

        $lines = array_filter(
            preg_split('/\R/', $content) ?: [],
            fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
        );

        return [null, $this->clean($lines)];
    }

    /**
     * @param  array<array-key, scalar>  $lines
     * @return list<string>
     */
    private function clean(array $lines): array
    {
        return array_values(array_filter(
            array_map(fn ($line): string => Str::squish((string) $line), $lines),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: list<int>, 1: list<string>, 2: list<string>} quest ids in file order, unmatched lines, ambiguous names
     */
    private function resolve(array $lines): array
    {
        $byName = $this->catalogByName($lines);
        $byGameId = Quest::whereIn('game_quest_id', array_filter($lines, ctype_digit(...)))->pluck('id', 'game_quest_id');

        $questIds = [];
        $unmatched = [];
        $ambiguous = [];

        foreach ($lines as $line) {
            $candidates = $byName[mb_strtolower($line)] ?? [];

            if ($candidates !== []) {
                $questIds[] = $candidates[0]->id;

                if (count($candidates) > 1) {
                    $ambiguous[] = $line;
                }

                continue;
            }

            if (ctype_digit($line) && $byGameId->has((int) $line)) {
                $questIds[] = $byGameId->get((int) $line);

                continue;
            }

            $unmatched[] = $line;
        }

        return [$questIds, $unmatched, array_values(array_unique($ambiguous))];
    }

    /**
     * Catalog quests keyed by lower-cased name. A few names are carried by
     * more than one quest; the lowest-level one comes first, which is the one
     * a levelling route nearly always means.
     *
     * @param  list<string>  $lines
     * @return array<string, list<Quest>>
     */
    private function catalogByName(array $lines): array
    {
        return Quest::query()
            ->whereIn(DB::raw('lower(name)'), array_map(mb_strtolower(...), $lines))
            ->orderBy('required_level')
            ->orderBy('id')
            ->get(['id', 'name', 'required_level'])
            ->groupBy(fn (Quest $quest): string => mb_strtolower($quest->name))
            ->map(fn ($quests): array => $quests->values()->all())
            ->all();
    }
}
