<?php

namespace App\Game\Data;

/**
 * One skill row on a cast_skills.php tab. The parenthetical after the name is
 * either "{trained}+{bonus}", "{trained}", or "Unlock at {level}".
 */
final readonly class SkillRow
{
    /**
     * @param  ?int  $trainedLevel  null when the parenthetical was missing or in a
     *                              shape we do not recognise — "unknown", never "zero"
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public ?int $trainedLevel,
        public ?int $bonusLevel,
        public ?int $unlockLevel,
        public bool $trainable,
    ) {}

    public function effectiveLevel(): int
    {
        return ($this->trainedLevel ?? 0) + ($this->bonusLevel ?? 0);
    }

    /**
     * Bonus levels alone do not make a skill usable — "(0+8)" is uncastable.
     */
    public function isCastable(): bool
    {
        return ($this->trainedLevel ?? 0) >= 1;
    }

    /**
     * Whether the page told us this skill's levels at all.
     */
    public function hasKnownLevels(): bool
    {
        return $this->trainedLevel !== null;
    }
}
