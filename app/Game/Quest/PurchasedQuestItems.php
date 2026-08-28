<?php

namespace App\Game\Quest;

/**
 * Quest items the game only sells. A step asking for one can never be
 * fulfilled by farming — the seeded source mobs for a Quest Shard are
 * end-game bosses that do not drop it — so a run that treats such a step as
 * ordinary work grinds until it gives up, and in list mode takes the rest of
 * the list down with it.
 *
 * The names live in config/outwar.php so a newly discovered one can be added
 * without a deploy.
 */
class PurchasedQuestItems
{
    public function matches(string $itemName): bool
    {
        foreach ($this->names() as $name) {
            if (mb_strtolower(trim($itemName)) === mb_strtolower(trim($name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        /** @var list<string> $names */
        $names = config('outwar.quest.purchased_items', []);

        return $names;
    }
}
