<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'name', 'start_time', 'end_time', 'grace_minutes', 'refresh_start', 'refresh_end',
        'overtime_start', 'overtime_end', 'monthly_duty_days', 'daily_duty_minutes',
        'allowed_late_days', 'default_bonus_percent', 'default_deduction_percent', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
