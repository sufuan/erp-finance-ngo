<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberVisit extends Model
{
    protected $table = 'member_visits';

    protected $fillable = ['member_id', 'visit_date', 'info', 'latitude', 'longitude', 'check_in_at', 'created_by'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
