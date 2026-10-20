<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Village extends Model
{
    protected $fillable = ['name', 'union_id', 'upazila_id'];

    public function union(): BelongsTo
    {
        return $this->belongsTo(Union::class);
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class);
    }
}
