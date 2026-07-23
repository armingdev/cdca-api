<?php

namespace App\Game\Enums;

/**
 * Class is always trainable; Ferocity / Preservation / Affliction are mutually
 * exclusive (a character invests in one); Misc holds utility effects.
 */
enum SkillSchool: string
{
    case ClassSkill = 'class';
    case Ferocity = 'ferocity';
    case Preservation = 'preservation';
    case Affliction = 'affliction';
    case Misc = 'misc';

    /**
     * The cast_skills.php?C= tab for this school. Class is the default page
     * (no C param). C=2 is the train action, never a tab.
     */
    public function tabParam(): ?int
    {
        return match ($this) {
            self::ClassSkill => null,
            self::Ferocity => 4,
            self::Preservation => 5,
            self::Affliction => 6,
            self::Misc => 7,
        };
    }
}
