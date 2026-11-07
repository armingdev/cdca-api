<?php

namespace App\Game\Enums;

/**
 * Which runs get a free worker first when every run worker is busy.
 *
 * Each priority is its own queue on the redis-runs connection, and the runs
 * supervisor drains them strictly in QUEUES order (Horizon `balance: false`).
 * It decides only who starts next — a running participant is never
 * interrupted for a higher-priority one.
 */
enum RunPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /** The supervisor's queue list, highest priority first. */
    public const array QUEUES = ['runs-high', 'runs', 'runs-low'];

    public function queue(): string
    {
        return match ($this) {
            self::High => 'runs-high',
            // The queue every run used before priorities existed.
            self::Normal => 'runs',
            self::Low => 'runs-low',
        };
    }

    /** Sort weight for ordering due work: lower goes first. */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }
}
