<?php

namespace App\Game\Exceptions;

/**
 * The game's reported position (or a per-request hash) disagrees with what we
 * expected — never trust the intended position; reload and re-plan.
 */
class DesyncException extends GameException
{
    public static function positionMismatch(int $expected, int $actual): self
    {
        return new self("Move desync: expected to land in room {$expected} but the game reports room {$actual}.");
    }

    public static function hashError(string $error): self
    {
        return new self("Per-request hash error (retryable): {$error}");
    }
}
