<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Member portal credentials (Step 8) — a SEPARATE auth identity from
 * staff users. The portal itself is built in Step 25; the guard is
 * registered there. Username defaults to the member's mobile number.
 */
class MemberUser extends Authenticatable
{
    protected $fillable = [
        'member_id', 'username', 'password', 'is_active', 'must_change_password',
        'last_login_at', 'failed_attempts', 'locked_until',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
