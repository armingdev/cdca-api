<?php

namespace App\Game\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';

    /** Stop requested; the worker exits at the next loop iteration. */
    case Stopping = 'stopping';

    /** Pause requested; the worker parks the participant at the next loop iteration. */
    case Pausing = 'pausing';

    /** Parked by the user; resumable via POST /runs/{run}/resume. */
    case Paused = 'paused';

    /** Parked by the engine (Circumspect cooldown, pass interval); auto-resumed at resume_at. */
    case Waiting = 'waiting';

    case Stopped = 'stopped';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Stopped, self::Completed, self::Failed], true);
    }

    /** Parked but not finished: the run will (or can) continue later. */
    public function isParked(): bool
    {
        return in_array($this, [self::Paused, self::Waiting], true);
    }

    /** A worker is active or about to be: the state can change without outside input. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::Stopping, self::Pausing], true);
    }

    /**
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Pending, self::Running, self::Stopping, self::Pausing];
    }
}
