<?php

namespace App\Models;

use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A top-level world grouping from the xowh seed's Zones catalog (31 zones).
 * Ids are the seed's own — never auto-generated. Zone 0 ("Deleted") collects
 * areas removed from the live game.
 */
class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
    ];

    /**
     * @return HasMany<Area, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
