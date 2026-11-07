<?php

namespace App\Game\Data;

use App\Game\Enums\PotionActivationOutcome;
use App\Models\CharacterPotion;

/**
 * What happened when a character tried to drink a potion.
 */
final readonly class PotionActivation
{
    public function __construct(
        public PotionActivationOutcome $outcome,
        public string $message,
        /** The character's stack after the attempt (null: never held it). */
        public ?CharacterPotion $stack = null,
    ) {}

    public function drank(): bool
    {
        return $this->outcome === PotionActivationOutcome::Activated;
    }
}
