<?php

namespace App\Game\Data;

/**
 * `crew_profile.php?id={crewId}` — works for any crew, ours or a rival's, and
 * carries the full member roster.
 *
 * Members arrive with id, name and level but **no attack hash** (the roster
 * renders no attack icon), so crew-members mode must mint one per target via
 * playersearch before attacking.
 */
final readonly class CrewRoster
{
    /**
     * @param  list<CrewMember>  $members
     * @param  array<int, string>  $allyCrews  crewId => name
     * @param  array<int, string>  $enemyCrews  crewId => name
     */
    public function __construct(
        public int $crewId,
        public string $name,
        public array $members,
        public ?string $leader = null,
        public ?int $totalMembers = null,
        public ?int $averageLevel = null,
        public array $allyCrews = [],
        public array $enemyCrews = [],
    ) {}
}
