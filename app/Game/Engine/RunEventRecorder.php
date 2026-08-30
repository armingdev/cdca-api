<?php

namespace App\Game\Engine;

use App\Game\Enums\RunEventType;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use Closure;
use Illuminate\Support\Str;

/**
 * The run's two log sinks behind one object: the live one-liner every client
 * polls (run_participants.last_activity, overwritten each time) and the
 * durable journal (run_events, append-only).
 *
 * Engines take this instead of the bare $log closure so a *decision* — a
 * skill skipped, a quest walked past, a park — leaves a trace that survives
 * the next line, while per-iteration chatter stays cheap.
 */
class RunEventRecorder
{
    public function __construct(private readonly RunParticipant $participant) {}

    /**
     * A decision worth keeping: writes the journal row and updates the live
     * line, so significant events are visible in both places.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        RunEventType $type,
        string $message,
        array $context = [],
        string $level = RunEvent::LEVEL_INFO,
    ): void {
        RunEvent::create([
            'run_id' => $this->participant->run_id,
            'run_participant_id' => $this->participant->id,
            'character_id' => $this->participant->character_id,
            'type' => $type,
            'level' => $level,
            // 497 + the ellipsis Str::limit appends = the column's 500.
            'message' => Str::limit($message, 497),
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);

        $this->activity($message);
    }

    /**
     * Loop chatter: the live line only. No journal row — a 200-quest run
     * would otherwise write tens of thousands of rows nobody reads.
     */
    public function activity(string $message): void
    {
        $this->participant->update(['last_activity' => Str::limit($message, 250)]);
    }

    /**
     * The engines' existing `Closure(string): void` log contract, backed by
     * this recorder's live line.
     *
     * @return Closure(string): void
     */
    public function logger(): Closure
    {
        return fn (string $message) => $this->activity($message);
    }
}
