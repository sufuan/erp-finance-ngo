<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Step 18 — one installment receipt (detail row; money lives in the sub-ledger). */
class LoanReceive extends Model
{
    protected $fillable = [
        'loan_account_id', 'member_id', 'receive_date', 'business_date',
        'installment_amount', 'investment_amount', 'profit_amount',
        'penalty_charge', 'transfer_from_deposit', 'deposit_account_id',
        'document_number', 'comments', 'voucher_id', 'deposit_voucher_id',
        'is_migrated', 'entry_by', 'entry_at', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'receive_date' => 'date', 'business_date' => 'date',
            'installment_amount' => 'decimal:2', 'investment_amount' => 'decimal:2',
            'profit_amount' => 'decimal:2', 'penalty_charge' => 'decimal:2',
            'transfer_from_deposit' => 'boolean', 'is_migrated' => 'boolean',
            'entry_at' => 'datetime', 'cancel_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LoanReceiveAllocation::class);
    }
}
