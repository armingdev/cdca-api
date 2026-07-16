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
}
