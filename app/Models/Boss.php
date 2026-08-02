<?php

namespace App\Models;

use Database\Factories\BossFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A world boss from the xowh seed's Bosses catalog. Ids are the game's own
 * boss ids (127-137) — never auto-generated. `rage_to_join` is the rage cost
 * to enter the boss fight.
 */
class Boss extends Model
{
    /** @use HasFactory<BossFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'nick',
        'rage_to_join',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rage_to_join' => 'integer',
        ];
    }
}
