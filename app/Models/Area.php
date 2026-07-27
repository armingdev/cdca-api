<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named world region from the xowh seed's Areas catalog (462 areas).
 * Ids are the seed's own — never auto-generated.
 */
class Area extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
    ];

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
