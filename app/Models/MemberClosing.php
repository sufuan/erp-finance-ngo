<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberClosing extends Model
{
    protected $table = 'member_closings';

    protected $fillable = ['member_id', 'closing_date', 'closing_reason', 'share_amount_at_closing', 'share_disposition', 'closed_by', 'closed_at', 'cancelled_by', 'cancelled_at'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
