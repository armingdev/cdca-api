<?php

namespace App\Game\Exceptions;

class LoginFailedException extends GameException
{
    public static function unexpectedStatus(int $status): self
    {
        return new self("Login did not redirect into the world (HTTP {$status}) — bad credentials or a changed login flow.");
    }

    /**
     * A 301 off the configured login host: the POST body is dropped on a
     * permanent redirect, so this must be fixed in config, not followed.
     */
    public static function hostMoved(string $from, string $to): self
    {
        return new self("The login host {$from} now redirects to {$to} — point OUTWAR_LOGIN_HOST at the new host.");
    }

    public static function badCredentials(): self
    {
        return new self('The game rejected the username or password.');
    }

    public static function missingSessionCookie(): self
    {
        return new self('Login response did not set an rg_sess_id cookie.');
    }

    public static function sessionRejected(): self
    {
        return new self('The session id is invalid or expired — the game did not recognize it.');
    }
}
