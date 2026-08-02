<?php

namespace App\Models;

use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named world region from the xowh seed's Areas catalog (462 areas).
 * Ids are the seed's own — never auto-generated.
 */
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'zone_id',
    ];

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
