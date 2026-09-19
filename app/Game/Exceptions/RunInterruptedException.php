<?php

namespace App\Game\Exceptions;

use App\Game\Enums\RunSignal;
use RuntimeException;

/**
 * A control signal (stop, pause, worker shutdown…) arrived in the middle of a
 * long stretch of game requests — a cross-world walk — and the stretch was
 * abandoned at a safe point between two requests so the run can honour it now
 * rather than at its destination.
 *
 * Deliberately NOT a GameException: the engines catch those to skip a room or
 * a quest and carry on, and an interrupt must unwind to the engine's own loop.
 */
class RunInterruptedException extends RuntimeException
{
    public function __construct(public readonly RunSignal $signal)
    {
        parent::__construct("Run interrupted: {$signal->value}.");
    }
}
