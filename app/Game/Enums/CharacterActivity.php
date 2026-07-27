<?php

namespace App\Game\Enums;

/**
 * What a character is doing right now — the live "status" column of the
 * fleet grid. Mirrored from its run participant's lifecycle (see
 * RunParticipant::transition()); Idle whenever no run drives it.
 */
enum CharacterActivity: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Waiting = 'waiting';
    case Paused = 'paused';

    public static function fromRunStatus(RunStatus $status): self
    {
        return match ($status) {
            RunStatus::Running, RunStatus::Stopping, RunStatus::Pausing => self::Running,
            RunStatus::Waiting, RunStatus::Pending => self::Waiting,
            RunStatus::Paused => self::Paused,
            default => self::Idle,
        };
    }
}
