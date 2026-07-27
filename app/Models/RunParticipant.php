<?php

namespace App\Models;

use App\Game\Enums\RunStatus;
use Carbon\CarbonInterface;
use Database\Factories\RunParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RunParticipant extends Model
{
    /** @use HasFactory<RunParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'run_id',
        'character_id',
        'status',
        'wins',
        'losses',
        'errors',
        'last_activity',
        'progress',
        'resume_at',
        'dispatch_token',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'wins' => 'integer',
            'losses' => 'integer',
            'errors' => 'integer',
            'progress' => 'array',
            'resume_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * The single choke point for participant state changes: sets the status,
     * activity line, and resume time together; merges (never replaces)
     * progress so counters survive partial updates; stamps finished_at on
     * terminal statuses and clears resume_at on anything that isn't Waiting.
     *
     * @param  array<string, mixed>|null  $progress
     */
    public function transition(
        RunStatus $status,
        ?string $reason = null,
        ?CarbonInterface $resumeAt = null,
        ?array $progress = null,
    ): void {
        $attributes = [
            'status' => $status,
            'resume_at' => $resumeAt,
        ];

        if ($reason !== null) {
            $attributes['last_activity'] = Str::limit($reason, 250);
        }

        if ($progress !== null) {
            $attributes['progress'] = array_merge($this->progress ?? [], $progress);
        }

        if ($status->isFinished()) {
            $attributes['finished_at'] = now();
        }

        $this->update($attributes);
    }
}
