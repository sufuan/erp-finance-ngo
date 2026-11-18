<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 17 — one fee row per fee type (binding — replaces any fees JSON). */
class LoanFee extends Model
{
    /** Fixed fee types + 'insurance' (rate-based) + 'custom' (named rows). */
    public const TYPES = ['form', 'health', 'risk', 'document', 'emi_locker', 'insurance', 'others', 'custom'];

    protected $fillable = ['loan_account_id', 'fee_type', 'label', 'amount', 'ledger_id', 'voucher_id'];

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
