<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Rga, $this>
     */
    public function rgas(): HasMany
    {
        return $this->hasMany(Rga::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return HasMany<AttackList, $this>
     */
    public function attackLists(): HasMany
    {
        return $this->hasMany(AttackList::class);
    }

    /**
     * @return HasMany<QuestList, $this>
     */
    public function questLists(): HasMany
    {
        return $this->hasMany(QuestList::class);
    }

    /**
     * cdca crew groups this user belongs to (as leader or member).
     *
     * @return BelongsToMany<CrewGroup, $this>
     */
    public function crewGroups(): BelongsToMany
    {
        return $this->belongsToMany(CrewGroup::class, 'crew_group_members')->withTimestamps();
    }

    /**
     * RGAs this user has shared into crew groups (any group — constrain on
     * `crew_group_rgas.crew_group_id` for one).
     *
     * @return BelongsToMany<Rga, $this>
     */
    public function sharedCrewRgas(): BelongsToMany
    {
        return $this->belongsToMany(Rga::class, 'crew_group_rgas', 'shared_by_user_id', 'rga_id')->withTimestamps();
    }

    /**
     * Queue the reset mail with a link into the Angular client.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
