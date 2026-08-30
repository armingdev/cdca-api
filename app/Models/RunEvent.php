<?php

namespace App\Models;

use App\Game\Enums\RunEventType;
use Database\Factories\RunEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunEvent extends Model
{
    /** @use HasFactory<RunEventFactory> */
    use HasFactory;

    public const string LEVEL_INFO = 'info';

    public const string LEVEL_WARNING = 'warning';

    public const string LEVEL_ERROR = 'error';

    /**
     * Append-only journal; created_at is the only timestamp and the recorder
     * stamps it, so Eloquent's pair-of-timestamps handling is off.
     */
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'run_participant_id',
        'character_id',
        'type',
        'level',
        'message',
        'context',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RunEventType::class,
            'context' => 'array',
            'created_at' => 'datetime',
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
     * @return BelongsTo<RunParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(RunParticipant::class, 'run_participant_id');
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
