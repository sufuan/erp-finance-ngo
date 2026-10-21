<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberTransfer extends Model
{
    protected $table = 'member_transfers';

    protected $fillable = ['member_id', 'from_samity_id', 'to_samity_id', 'from_branch_id', 'to_branch_id', 'transfer_date', 'reason', 'created_by'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
