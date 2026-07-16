<?php

namespace App\Models;

use App\Game\Enums\SkillSchool;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    /** The game's Circumspect skill id — the "Circ" exp buff used for run gating. */
    public const int CIRCUMSPECT_ID = 3008;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'school',
        'rage_cost',
        'cooldown_minutes',
        'duration_minutes',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'school' => SkillSchool::class,
            'rage_cost' => 'integer',
            'cooldown_minutes' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }
}
