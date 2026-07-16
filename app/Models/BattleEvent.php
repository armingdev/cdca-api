<?php

namespace App\Models;

use App\Game\Enums\BattleOutcome;
use Database\Factories\BattleEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BattleEvent extends Model
{
    /** @use HasFactory<BattleEventFactory> */
    use HasFactory;

    /**
     * Append-only journal; occurred_at is the only timestamp.
     */
    public $timestamps = false;

    protected $fillable = [
        'character_id',
        'mob_id',
        'room_id',
        'battle_id',
        'outcome',
        'exp_gained',
        'gold_gained',
        'drop_name',
        'fail_reason',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => BattleOutcome::class,
            'battle_id' => 'integer',
            'exp_gained' => 'integer',
            'gold_gained' => 'integer',
            'room_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @return BelongsTo<Mob, $this>
     */
    public function mob(): BelongsTo
    {
        return $this->belongsTo(Mob::class);
    }
}
