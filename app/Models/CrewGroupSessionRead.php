<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leader read of a crew group's shared sessions (audit trail).
 */
class CrewGroupSessionRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'crew_group_id',
        'user_id',
        'rga_ids',
        'verified',
        'ip',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rga_ids' => 'array',
            'verified' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CrewGroup, $this>
     */
    public function crewGroup(): BelongsTo
    {
        return $this->belongsTo(CrewGroup::class);
    }

    /**
     * The leader who read.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
