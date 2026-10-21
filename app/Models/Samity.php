<?php

namespace App\Models;

use App\Models\Scopes\SamityScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([SamityScope::class])]
class Samity extends Model
{
    protected $fillable = [
        'samity_code', 'branch_id', 'name', 'referred_by', 'address', 'open_date',
        'working_area_id', 'field_officer_id', 'samity_phone', 'center_type',
        'start_time', 'samity_day', 'date_of_month', 'manager_id', 'latitude',
        'longitude', 'status', 'closed_by', 'closed_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'open_date' => 'date',
            'closed_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function workingArea(): BelongsTo
    {
        return $this->belongsTo(WorkingArea::class);
    }

    public function fieldOfficer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'field_officer_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function dayChanges(): HasMany
    {
        return $this->hasMany(SamityDayChange::class);
    }

    public function officerChanges(): HasMany
    {
        return $this->hasMany(SamityOfficerChange::class);
    }

    public function managerChanges(): HasMany
    {
        return $this->hasMany(SamityManagerChange::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
