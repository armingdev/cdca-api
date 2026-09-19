<?php

namespace App\Game\Data;

use App\Game\Enums\RageReserveEvent;
use Carbon\CarbonInterface;

/**
 * One stretch during which a run holds off to save rage: from some hours
 * before an event starts until the event is over.
 */
final readonly class RageReserveWindow
{
    public function __construct(
        public RageReserveEvent $event,
        public CarbonInterface $reserveFrom,
        public CarbonInterface $eventStartsAt,
        public CarbonInterface $resumeAt,
    ) {}

    public function isOpen(): bool
    {
        return now()->greaterThanOrEqualTo($this->reserveFrom) && now()->lessThan($this->resumeAt);
    }

    public function reason(): string
    {
        return "Saving rage for the {$this->event->label()} ({$this->eventStartsAt->format('Y-m-d H:i')}) — resumes {$this->resumeAt->format('Y-m-d H:i')}.";
    }
}
