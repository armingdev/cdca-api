<?php

namespace App\Models;

use Database\Factories\RgaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rga extends Model
{
    /** @use HasFactory<RgaFactory> */
    use HasFactory;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INVALID = 'invalid';

    protected $fillable = [
        'user_id',
        'username',
        'password',
        'cookies',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'cookies',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'cookies' => 'encrypted:array',
            'last_login_at' => 'datetime',
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
     * @return HasMany<Character, $this>
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function hasSession(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! empty($this->cookies);
    }
}
