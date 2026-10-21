<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 10 — a hold against a deposit account securing a loan. */
class DepositLien extends Model
{
    protected $fillable = [
        'deposit_account_id', 'loan_proposal_id', 'loan_account_id',
        'amount', 'status', 'created_by', 'released_by', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'released_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'deposit_account_id');
    }
}
