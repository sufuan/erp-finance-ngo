<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 12 — one deposit account closing (payable = balance + profit − charge − penalty). */
class DepositClosing extends Model
{
    protected $fillable = [
        'account_id', 'closing_date', 'balance', 'profit_payment',
        'closing_charge', 'payable_amount', 'reason',
        'closed_by', 'closed_at', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'closing_date' => 'date',
            'balance' => 'decimal:2',
            'profit_payment' => 'decimal:2',
            'closing_charge' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'account_id');
    }
}
