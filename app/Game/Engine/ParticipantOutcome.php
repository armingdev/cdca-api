<?php

namespace App\Game\Engine;

use App\Game\Enums\RunStatus;
use Carbon\CarbonInterface;

/**
 * A mode job's verdict for one participant: the status to persist, the
 * human-readable reason, and — for parked outcomes — when to auto-resume
 * and what progress to carry into the next cycle.
 */
final readonly class ParticipantOutcome
{
    /**
     * @param  array<string, mixed>|null  $progress
     */
    public function __construct(
        public RunStatus $status,
        public string $reason,
        public ?CarbonInterface $resumeAt = null,
        public ?array $progress = null,
    ) {}
}
