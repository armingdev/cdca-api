<?php

namespace App\Game\Data;

/**
 * One row of a crew roster: `Rank | Name | Level`.
 */
final readonly class CrewMember
{
    public function __construct(
        public int $playerId,
        public string $name,
        public ?int $level = null,
        public string $rank = '',
    ) {}

    public function toAttackTarget(): AttackTarget
    {
        return new AttackTarget(
            playerId: $this->playerId,
            name: $this->name,
            level: $this->level,
        );
    }
}
