<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Step 13 — end-of-day balance snapshot (written by the Step 27 EOD job).
 * The ONLY input for average/minimum/maximum profit math.
 */
class DailyBalance extends Model
{
    protected $fillable = ['account_type', 'account_id', 'balance_date', 'balance'];

    protected function casts(): array
    {
        return ['balance_date' => 'date', 'balance' => 'decimal:2'];
    }
}
