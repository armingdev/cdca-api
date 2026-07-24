<?php

namespace App\Models;

use Database\Factories\JunkItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A known junk item name (seeded from data/xowh-seed JunkItems). Backpack
 * items whose rollover name matches are safe to auto-drop.
 */
class JunkItem extends Model
{
    /** @use HasFactory<JunkItemFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];
}
