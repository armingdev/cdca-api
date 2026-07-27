<?php

namespace App\Game\Exceptions;

/**
 * Thrown when a run launch names characters that are already enrolled in
 * another run that has not finished — one character must never be driven by
 * two workers at once.
 */
class CharactersBusyException extends GameException
{
    /**
     * @param  list<string>  $names
     */
    public static function forCharacters(array $names): self
    {
        return new self('Already in an active run: '.implode(', ', $names).'.');
    }
}
