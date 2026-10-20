<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Canonical sub-ledger row — source of truth for per-account movements. */
class AccountTransaction extends Model
{
    protected $fillable = [
        'branch_id', 'account_type', 'account_id', 'tran_code', 'amount', 'balance_after',
        'tran_date', 'business_date', 'voucher_id', 'ref_type', 'ref_id',
        'entry_by', 'entry_at', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'balance_after' => 'decimal:2',
            'tran_date' => 'date', 'business_date' => 'date',
            'entry_at' => 'datetime', 'cancel_at' => 'datetime',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isCancelled(): bool
    {
        return $this->cancel_at !== null;
    }
}
