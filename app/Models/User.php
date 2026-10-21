<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password', 'employee_id', 'locale', 'is_active', 'must_change_password', 'totp_secret', 'totp_confirmed_at', 'last_login_at', 'last_login_ip', 'failed_attempts', 'locked_until'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /** Branches explicitly assigned in the admin UI (Step 2). */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')->withPivot('include_children')->withTimestamps();
    }

    /** Samities assigned for Field Officer row scoping (Step 7). */
    public function samities(): BelongsToMany
    {
        return $this->belongsToMany(Samity::class, 'samity_user')->withTimestamps();
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function hasTotpEnabled(): bool
    {
        return ! empty($this->totp_secret) && $this->totp_confirmed_at !== null;
    }
}
