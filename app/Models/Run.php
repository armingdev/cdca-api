<?php

namespace App\Models;

use App\Game\Enums\RunMode;
use App\Game\Enums\RunSignal;
use App\Game\Enums\RunStatus;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /** How long a control signal stays readable — outlives the longest job (2h). */
    private const int SIGNAL_TTL_SECONDS = 28800;

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
     * Broadcast a control signal to this run's engine loops via the cache —
     * the fast path they poll every iteration. DB statuses stay the source
     * of truth; callers must update them alongside.
     */
    public function signal(RunSignal $signal): void
    {
        Cache::put($this->signalKey(), $signal->value, self::SIGNAL_TTL_SECONDS);
    }

    public function clearSignal(): void
    {
        Cache::forget($this->signalKey());
    }

    public function currentSignal(): RunSignal
    {
        return RunSignal::tryFrom((string) Cache::get($this->signalKey())) ?? RunSignal::None;
    }

    /**
     * Request a graceful stop: live workers exit at their next loop
     * iteration; parked participants (no worker) are finalized directly.
     * Disarms auto-restart — a stop is meant to stick.
     */
    public function requestStop(): void
    {
        $this->update(['status' => RunStatus::Stopping, 'restart_every_minutes' => null]);
        $this->signal(RunSignal::Stop);

        $this->participants()
            ->whereIn('status', [RunStatus::Running, RunStatus::Pausing, RunStatus::Stopping])
            ->update(['status' => RunStatus::Stopping]);

        $this->participants()
            ->whereIn('status', [RunStatus::Pending, RunStatus::Waiting, RunStatus::Paused])
            ->update([
                'status' => RunStatus::Stopped,
                'resume_at' => null,
                'finished_at' => now(),
                'last_activity' => 'Stopped.',
            ]);

        $this->refreshStatus();
    }

    /**
     * Request a graceful pause: live workers park at their next loop
     * iteration; participants without an active worker are parked directly.
     */
    public function requestPause(): void
    {
        $this->signal(RunSignal::Pause);

        $this->participants()
            ->where('status', RunStatus::Running)
            ->update(['status' => RunStatus::Pausing]);

        $this->participants()
            ->whereIn('status', [RunStatus::Pending, RunStatus::Waiting])
            ->update([
                'status' => RunStatus::Paused,
                'resume_at' => null,
                'last_activity' => 'Paused.',
            ]);

        $this->refreshStatus();
    }

    /**
     * Roll participant statuses up into the run status once no worker is
     * active: any Waiting participant keeps the run self-propelling, then
     * Paused, then the finished precedence failure > stop > completed.
     */
    public function refreshStatus(): void
    {
        if ($this->participants()->whereIn('status', RunStatus::inFlight())->exists()) {
            return;
        }

        $status = match (true) {
            $this->participants()->where('status', RunStatus::Waiting)->exists() => RunStatus::Waiting,
            $this->participants()->where('status', RunStatus::Paused)->exists() => RunStatus::Paused,
            $this->participants()->where('status', RunStatus::Failed)->exists() => RunStatus::Failed,
            $this->participants()->where('status', RunStatus::Stopped)->exists() => RunStatus::Stopped,
            default => RunStatus::Completed,
        };

        $this->update(['status' => $status]);

        if ($status->isFinished()) {
            $this->clearSignal();
        }
    }

    private function signalKey(): string
    {
        return "run:signal:{$this->id}";
    }
}
