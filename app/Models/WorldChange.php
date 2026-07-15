<?php

namespace App\Models;

use Database\Factories\WorldChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorldChange extends Model
{
    /** @use HasFactory<WorldChangeFactory> */
    use HasFactory;

    /**
     * Append-only journal; observed_at is the only timestamp.
     */
    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'field',
        'old_value',
        'new_value',
        'character_id',
        'observed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'observed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
