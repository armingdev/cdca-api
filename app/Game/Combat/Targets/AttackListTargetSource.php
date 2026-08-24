<?php

namespace App\Game\Combat\Targets;

use App\Game\Combat\PvpAttackService;
use App\Game\Data\AttackTarget;
use App\Models\AttackList;
use App\Models\Character;
use App\Models\PlayerCharacter;

/**
 * Targets from a user-authored attack list.
 *
 * Entries are stored by name, so each costs a search to resolve — but the
 * search result already carries the attack hash, so no extra request is
 * needed to attack. Resolved ids are cached back onto the list row, which
 * also protects the list against a target renaming itself later.
 */
class AttackListTargetSource implements PvpTargetSource
{
    public function __construct(
        private readonly Character $character,
        private readonly AttackList $list,
        private readonly PvpAttackService $attacker,
    ) {}

    public static function forList(Character $character, AttackList $list): self
    {
        return new self($character, $list, PvpAttackService::forCharacter($character));
    }

    /**
     * @return list<AttackTarget>
     */
    public function targets(): array
    {
        $targets = [];

        foreach ($this->list->targets as $entry) {
            $target = $this->attacker->findTarget($entry->name);

            if ($target === null) {
                continue;
            }

            if ($entry->player_id !== $target->playerId) {
                $entry->update(['player_id' => $target->playerId]);
            }

            PlayerCharacter::remember($this->character->server_id, $target);

            $targets[] = $target;
        }

        return $targets;
    }

    public function label(): string
    {
        return "attack list '{$this->list->name}'";
    }
}
