<?php

namespace App\Game\Items;

use App\Game\Data\EquipmentSet;
use App\Game\Data\GearOptimizeSummary;
use App\Game\Data\ItemDetail;
use App\Game\Exceptions\GameException;
use App\Models\Character;
use Closure;

/**
 * Auto-equip: scans the regular backpack tab, scores every equippable item the
 * character is high enough level for, and equips the best one per slot. This
 * is what carries a fresh character through its first quests — it starts naked
 * and every drop is an upgrade.
 *
 * Equipping into an occupied slot auto-swaps (VERIFIED 2026-08-09): the
 * displaced item returns to the backpack, so there is never an unequip step.
 *
 * What the character already wears comes from equipment.php, read once per
 * instance. Worn items are only scored for slots a backpack candidate is
 * actually competing for, so a fully-geared character does not pay a tooltip
 * call for every piece it owns. Rollover lookups are cached per instance too,
 * so repeated passes within a run only pay for iids they have not seen yet
 * (i.e. fresh drops).
 */
class GearManager
{
    /** Score weights: attack dominates, elemental damage next, hp breaks ties. */
    private const int ATTACK_WEIGHT = 10;

    private const int ELEMENT_WEIGHT = 5;

    /** Elemental damage labels item_rollover prints (resists are separate stats). */
    private const array ELEMENTS = ['holy', 'arcane', 'fire', 'kinetic', 'shadow', 'vile energy'];

    /** @var array<int, ItemDetail|null> iid → rollover; null marks an unreadable item we stop retrying */
    private array $detailCache = [];

    /** @var array<string, array{iid: int, score: int}|null> slot → the item we believe is worn there (null = slot is empty) */
    private array $believedEquipped = [];

    private ?EquipmentSet $worn = null;

    public function __construct(
        private readonly BackpackService $backpack,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(BackpackService::forCharacter($character));
    }

    /**
     * Equip the best candidate for every slot we can improve. Items requiring
     * a higher level than the character are skipped, so a pass re-run after a
     * level-up can pick up gear that was previously out of reach.
     *
     * @param  Closure(string): void|null  $log
     */
    public function optimize(int $characterLevel, ?Closure $log = null): GearOptimizeSummary
    {
        $log ??= fn (string $message) => null;

        $items = $this->backpack->contents('regular')->items;
        $best = [];

        foreach ($items as $item) {
            if (! $item->canEquip()) {
                continue;
            }

            $detail = $this->detail($item->iid);

            if ($detail?->slot === null) {
                continue;
            }

            if ($detail->requiredLevel !== null && $detail->requiredLevel > $characterLevel) {
                continue;
            }

            $score = $this->score($detail);
            $leader = $best[$detail->slot] ?? null;

            // Ties break on the lower iid so a pass is deterministic whatever
            // order the backpack happens to list items in.
            if ($leader === null
                || $score > $leader['score']
                || ($score === $leader['score'] && $item->iid < $leader['iid'])) {
                $best[$detail->slot] = ['iid' => $item->iid, 'score' => $score, 'name' => $detail->name];
            }
        }

        $equippedNames = [];

        foreach ($best as $slot => $candidate) {
            $worn = $this->wornIn($slot);

            if ($worn !== null && $candidate['score'] <= $worn['score']) {
                continue;
            }

            try {
                $this->backpack->equip([$candidate['iid']]);
            } catch (GameException $exception) {
                $log("Could not equip {$candidate['name']}: {$exception->getMessage()}");

                continue;
            }

            $this->believedEquipped[$slot] = ['iid' => $candidate['iid'], 'score' => $candidate['score']];
            $equippedNames[] = $candidate['name'];
            $log("Equipped {$candidate['name']} ({$slot}).");
        }

        return new GearOptimizeSummary(
            scanned: count($items),
            equipped: count($equippedNames),
            equippedNames: $equippedNames,
        );
    }

    /**
     * What the character wears in $slot, scored on first use and remembered
     * after that (our own equips keep it current). Reading the doll is one
     * request; scoring costs a tooltip only for the contested slots.
     *
     * @return array{iid: int, score: int}|null null when the slot is empty
     */
    private function wornIn(string $slot): ?array
    {
        if (array_key_exists($slot, $this->believedEquipped)) {
            return $this->believedEquipped[$slot];
        }

        $this->worn ??= $this->backpack->equipped();
        $best = null;

        foreach ($this->worn->itemsInSlotNamed($slot) as $item) {
            $detail = $this->detail($item->iid);

            if ($detail === null) {
                continue;
            }

            $score = $this->score($detail);

            if ($best === null || $score > $best['score']) {
                $best = ['iid' => $item->iid, 'score' => $score];
            }
        }

        return $this->believedEquipped[$slot] = $best;
    }

    private function detail(int $iid): ?ItemDetail
    {
        if (array_key_exists($iid, $this->detailCache)) {
            return $this->detailCache[$iid];
        }

        try {
            return $this->detailCache[$iid] = $this->backpack->itemDetail($iid);
        } catch (GameException) {
            return $this->detailCache[$iid] = null;
        }
    }

    private function score(ItemDetail $detail): int
    {
        $elemental = 0;

        foreach (self::ELEMENTS as $element) {
            $elemental += $detail->stat($element);
        }

        return self::ATTACK_WEIGHT * $detail->stat('atk')
            + self::ELEMENT_WEIGHT * $elemental
            + $detail->stat('hp');
    }
}
