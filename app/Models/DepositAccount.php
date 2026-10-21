<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Step 10 — a member's deposit account. Balances are cached here; the
 * source of truth is the account_transactions sub-ledger (Step 3A),
 * reconciled nightly.
 */
class DepositAccount extends Model
{
    protected $fillable = [
        'account_number', 'branch_id', 'member_id', 'deposit_product_id',
        'title', 'address', 'phone', 'opening_date', 'profit_rate',
        'installment_amount', 'maturity_date', 'nominee_id', 'credit_limit',
        'is_active', 'closing_date', 'total_credit', 'total_debit',
        'current_balance', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
            'maturity_date' => 'date',
            'closing_date' => 'date',
            'is_active' => 'boolean',
            'profit_rate' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'total_debit' => 'decimal:2',
            'current_balance' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(DepositProduct::class, 'deposit_product_id');
    }

    public function nominee(): BelongsTo
    {
        return $this->belongsTo(Nominee::class);
    }

    public function liens(): HasMany
    {
        return $this->hasMany(DepositLien::class);
    }

    public function activeLiens(): HasMany
    {
        return $this->liens()->where('status', 'Active');
    }
}
