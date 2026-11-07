<?php

namespace App\Game\Data;

use App\Game\Enums\PotionKind;

/**
 * What a potion's description says it does.
 */
final readonly class PotionEffect
{
    public function __construct(
        public PotionKind $kind,
        /** Nominal length of a buff. The game's own "time left" wins over this. */
        public ?int $durationMinutes = null,
        /** Rage awarded on drinking, when the text names an amount. */
        public ?int $rageAmount = null,
    ) {}
}
