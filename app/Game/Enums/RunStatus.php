<?php

namespace App\Game\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';

    /** Stop requested; the worker exits at the next loop iteration. */
    case Stopping = 'stopping';

    case Stopped = 'stopped';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Stopped, self::Completed, self::Failed], true);
    }
}
