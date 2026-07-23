<?php

namespace App\Models;

use App\Game\Enums\SkillSchool;
use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    protected $fillable = [
        'rga_id',
        'suid',
        'server_id',
        'name',
        'level',
        'rage',
        'exp',
        'skill_points',
        'school',
        'crew',
        'current_room_id',
        'last_stats_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suid' => 'integer',
            'server_id' => 'integer',
            'level' => 'integer',
            'rage' => 'integer',
            'exp' => 'integer',
            'skill_points' => 'integer',
            'school' => SkillSchool::class,
            'current_room_id' => 'integer',
            'last_stats_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rga, $this>
     */
    public function rga(): BelongsTo
    {
        return $this->belongsTo(Rga::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function currentRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'current_room_id');
    }

    /**
     * @return HasMany<CharacterSkill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class);
    }

    /**
     * @return HasMany<BattleEvent, $this>
     */
    public function battleEvents(): HasMany
    {
        return $this->hasMany(BattleEvent::class);
    }

    public function serverHost(): string
    {
        return config("outwar.servers.{$this->server_id}.host");
    }
}
