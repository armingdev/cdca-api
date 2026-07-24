<?php

namespace App\Game\Items;

use App\Game\Data\JunkDropSummary;
use App\Game\Exceptions\GameException;
use App\Models\Character;
use App\Models\JunkItem;
use Closure;

/**
 * Auto-drop-junk: scans the regular backpack tab and deletes every item whose
 * `data-name` matches the seeded junk-item list. Names come straight from the
 * backpack HTML — no per-item rollover calls needed.
 */
class JunkDropper
{
    public function __construct(
        private readonly Character $character,
        private readonly BackpackService $backpack,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character, BackpackService::forCharacter($character));
    }

    /**
     * @param  Closure(string): void|null  $log
     */
    public function dropJunk(?Closure $log = null): JunkDropSummary
    {
        $log ??= fn (string $message) => null;

        $answer = $this->character->rga->security_answer;

        if ($answer === null || $answer === '') {
            $log('Junk drop skipped: the RGA has no security answer configured.');

            return new JunkDropSummary(scanned: 0, dropped: 0, droppedNames: []);
        }

        $junkNames = JunkItem::pluck('name')
            ->map(fn (string $name): string => strtolower($name))
            ->flip();

        $items = $this->backpack->contents('regular')->items;
        $droppedNames = [];

        foreach ($items as $item) {
            if (! isset($junkNames[strtolower($item->name)])) {
                continue;
            }

            try {
                $this->backpack->delete([$item->iid]);
            } catch (GameException $exception) {
                $log("Could not drop {$item->name}: {$exception->getMessage()}");

                continue;
            }

            $droppedNames[] = $item->name;
            $log("Dropped junk: {$item->name}");
        }

        return new JunkDropSummary(
            scanned: count($items),
            dropped: count($droppedNames),
            droppedNames: $droppedNames,
        );
    }
}
