<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 19 — risk-fund / loan-insurance claim (death / disability). */
class InsuranceClaim extends Model
{
    protected $fillable = [
        'member_id', 'loan_account_id', 'claim_type', 'claim_date', 'amount',
        'documents', 'status', 'decided_by', 'decided_at', 'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date', 'amount' => 'decimal:2', 'documents' => 'array',
            'decided_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
