<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 13 — per-account installment (deposit collection) profile. */
class TransactionProfile extends Model
{
    protected $fillable = [
        'deposit_account_id', 'installment_amount', 'from_date', 'to_date', 'comments',
    ];

    protected function casts(): array
    {
        return [
            'installment_amount' => 'decimal:2',
            'from_date' => 'date', 'to_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'deposit_account_id');
    }
}
