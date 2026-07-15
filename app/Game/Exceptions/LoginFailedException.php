<?php

namespace App\Game\Exceptions;

class LoginFailedException extends GameException
{
    public static function unexpectedStatus(int $status): self
    {
        return new self("Login did not redirect into the world (HTTP {$status}) — bad credentials or a changed login flow.");
    }

    public static function missingSessionCookie(): self
    {
        return new self('Login response did not set an rg_sess_id cookie.');
    }
}
