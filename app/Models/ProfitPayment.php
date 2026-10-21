<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 13 — cash payment of previously distributed profit. */
class ProfitPayment extends Model
{
    protected $fillable = [
        'deposit_account_id', 'profit_amount', 'payment_date',
        'comments', 'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date', 'profit_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'deposit_account_id');
    }
}
