<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'code', 'name', 'type', 'parent_branch_id', 'manager_id', 'opening_date',
        'country', 'division', 'district', 'upazila', 'union',
        'manager_phone', 'address', 'latitude', 'longitude', 'status',
        'created_by', 'updated_by',
        // Geo FKs (Step 4); string columns above remain the fallback.
        'country_id', 'division_id', 'district_id', 'upazila_id', 'union_id',
    ];

    protected $casts = [
        'opening_date' => 'date',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'parent_branch_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Branch::class, 'parent_branch_id');
    }

    public function businessDays(): HasMany
    {
        return $this->hasMany(BusinessDay::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    /**
     * Ids of this branch plus all descendants (hierarchy roll-up, Step 2).
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $next = static::whereIn('parent_branch_id', $frontier)->pluck('id')->all();
            $next = array_diff($next, $ids);
            if (! $next) {
                break;
            }
            $ids = [...$ids, ...$next];
            $frontier = $next;
        }

        return $ids;
    }
}
