<?php

namespace App\Game\Enums;

enum PotionActivationOutcome: string
{
    /** "{Potion} activated!" — one consumed. */
    case Activated = 'activated';

    /** "You have already consumed one of these potions, please wait until it wears off" — nothing consumed. */
    case AlreadyActive = 'already_active';

    /** None left in the potion tab. */
    case OutOfStock = 'out_of_stock';

    /** Any other answer; backed off before the next try. */
    case Refused = 'refused';
}
