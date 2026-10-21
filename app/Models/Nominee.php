<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Nominee extends Model
{
    protected $fillable = ['member_id', 'name', 'dob', 'address', 'phone', 'relation', 'photo'];

    protected function casts(): array
    {
        return ['dob' => 'date'];
    }

    /** Age is always derived from dob, never stored (plan Step 8). */
    public function getAgeAttribute(): ?int
    {
        return $this->dob ? Carbon::parse($this->dob)->age : null;
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
