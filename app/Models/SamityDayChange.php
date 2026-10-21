<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SamityDayChange extends Model
{
    protected $table = 'samity_day_changes';

    protected $fillable = [
        'samity_id', 'change_date', 'effective_from', 'previous_value',
        'new_value', 'comments', 'created_by',
    ];

    protected function casts(): array
    {
        return ['change_date' => 'date', 'effective_from' => 'date'];
    }

    public function samity(): BelongsTo
    {
        return $this->belongsTo(Samity::class);
    }
}
