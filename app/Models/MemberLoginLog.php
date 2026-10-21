<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLoginLog extends Model
{
    protected $table = 'member_login_logs';

    protected $fillable = ['member_id', 'ip', 'user_agent', 'success'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
