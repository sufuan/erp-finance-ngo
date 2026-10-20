<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Union extends Model
{
    protected $table = 'unions';

    protected $fillable = ['name', 'upazila_id'];

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class);
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class);
    }
}
