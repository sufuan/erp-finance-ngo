<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessDay extends Model
{
    protected $fillable = [
        'branch_id', 'business_date', 'is_closed', 'closed_by', 'closed_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'business_date' => 'date',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
