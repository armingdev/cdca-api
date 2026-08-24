<?php

namespace App\Game\Combat\Targets;

use App\Game\Combat\PvpAttackService;
use App\Game\Data\AttackTarget;
use App\Models\Character;
use App\Models\PlayerCharacter;

/**
 * Targets from an ad-hoc list of names — the CLI's `--target` option, and the
 * attack-list mode's fallback when a run supplies names inline rather than
 * pointing at a saved AttackList.
 *
 * Each name costs one search, which also yields the attack hash.
 */
class NameListTargetSource implements PvpTargetSource
{
    /**
     * @param  list<string>  $names
     */
    public function __construct(
        private readonly Character $character,
        private readonly array $names,
        private readonly PvpAttackService $attacker,
    ) {}

    /**
     * @param  list<string>  $names
     */
    public static function forNames(Character $character, array $names): self
    {
        return new self($character, $names, PvpAttackService::forCharacter($character));
    }

    /**
     * @return list<AttackTarget>
     */
    public function targets(): array
    {
        $targets = [];

        foreach ($this->names as $name) {
            $target = $this->attacker->findTarget($name);

            if ($target === null) {
                continue;
            }

            PlayerCharacter::remember($this->character->server_id, $target);

            $targets[] = $target;
        }

        return $targets;
    }

    public function label(): string
    {
        return 'name list';
    }
}
