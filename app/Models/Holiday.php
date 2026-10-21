<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = ['branch_id', 'samity_id', 'holiday_date', 'details', 'created_by'];

    protected function casts(): array
    {
        return ['holiday_date' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function samity(): BelongsTo
    {
        return $this->belongsTo(Samity::class);
    }
}
