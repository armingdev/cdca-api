<?php

namespace App\Models;

use App\Game\Enums\PotionKind;
use Database\Factories\PotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One potion of the game's catalog (see the create_potions_table migration).
 */
class Potion extends Model
{
    /** @use HasFactory<PotionFactory> */
    use HasFactory;

    protected $fillable = [
        'game_item_id',
        'name',
        'image',
        'level',
        'player_bound',
        'description',
        'kind',
        'duration_minutes',
        'rage_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'game_item_id' => 'integer',
            'level' => 'integer',
            'player_bound' => 'boolean',
            'kind' => PotionKind::class,
            'duration_minutes' => 'integer',
            'rage_amount' => 'integer',
        ];
    }
}
