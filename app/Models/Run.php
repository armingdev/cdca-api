<?php

namespace App\Models;

use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mode',
        'config',
        'cast_on_start',
        'require_circumspect',
        'status',
        'restart_every_minutes',
        'start_at',
        'last_started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => RunMode::class,
            'status' => RunStatus::class,
            'config' => 'array',
            'cast_on_start' => 'boolean',
            'require_circumspect' => 'boolean',
            'restart_every_minutes' => 'integer',
            'start_at' => 'datetime',
            'last_started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<RunParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(RunParticipant::class);
    }

    public function allParticipantsFinished(): bool
    {
        return $this->participants()
            ->whereNotIn('status', [RunStatus::Stopped, RunStatus::Completed, RunStatus::Failed])
            ->doesntExist();
    }

    /**
     * Settle the run-level status once every participant has finished:
     * any failure wins, then any stop, otherwise completed.
     */
    public function refreshStatus(): void
    {
        if (! $this->allParticipantsFinished()) {
            return;
        }

        $status = match (true) {
            $this->participants()->where('status', RunStatus::Failed)->exists() => RunStatus::Failed,
            $this->participants()->where('status', RunStatus::Stopped)->exists() => RunStatus::Stopped,
            default => RunStatus::Completed,
        };

        $this->update(['status' => $status]);
    }
}
