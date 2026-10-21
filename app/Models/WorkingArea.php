<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Working area (manual master). The geo chain is stored as plain
 * bd-geo-info package values (id string + name, all optional); only the
 * village remains a real database relation.
 */
class WorkingArea extends Model
{
    protected $fillable = [
        'name',
        'division_id', 'division_name',
        'district_id', 'district_name',
        'upazila_id', 'upazila_name',
        'union_id', 'union_name',
        'village_id',
    ];

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }
}
