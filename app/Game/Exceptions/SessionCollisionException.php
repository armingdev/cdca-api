<?php

namespace App\Game\Exceptions;

class SessionCollisionException extends GameException
{
    public static function booted(): self
    {
        return new self('Game session was invalidated (Rampid Gaming Login page returned) — someone logged in elsewhere.');
    }
}
