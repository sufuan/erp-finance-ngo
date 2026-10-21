<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 17 — one fee row per fee type (binding — replaces any fees JSON). */
class LoanFee extends Model
{
    public const TYPES = ['form', 'health', 'risk', 'document', 'emi_locker', 'others'];

    protected $fillable = ['loan_account_id', 'fee_type', 'amount', 'ledger_id', 'voucher_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
