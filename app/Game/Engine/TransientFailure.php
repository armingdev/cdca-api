<?php

namespace App\Game\Engine;

use App\Game\Exceptions\TransientGameException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Http\Client\ConnectionException;
use RedisException;
use Throwable;

/**
 * Tells "the world hiccuped" from "this run is broken". A DNS timeout, a game
 * server that would not answer, a Redis or Postgres socket that dropped: none
 * of them say anything about the run, and all of them pass on their own, so a
 * run that meets one parks and retries instead of failing for good.
 *
 * Everything else — a parse failure, a game rule, a bug — stays terminal.
 */
class TransientFailure
{
    public function __construct(private readonly LostConnectionDetector $lostConnections) {}

    public function matches(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || $exception instanceof RedisException
            || $exception instanceof TransientGameException
            || $this->lostConnections->causedByLostConnection($exception);
    }
}
