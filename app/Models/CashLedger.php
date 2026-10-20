<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cached cash-in-hand per user; truth = account_transactions (cash). */
class CashLedger extends Model
{
    protected $fillable = ['user_id', 'branch_id', 'current_balance'];

    protected function casts(): array
    {
        return ['current_balance' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
