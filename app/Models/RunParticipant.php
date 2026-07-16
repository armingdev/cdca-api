<?php

namespace App\Models;

use App\Game\Enums\RunStatus;
use Database\Factories\RunParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function stopRequested(): bool
    {
        return $this->fresh()->status === RunStatus::Stopping;
    }
}
