<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 16 — a loan proposal; approval may create an FDR lien. */
class LoanProposal extends Model
{
    protected $fillable = [
        'branch_id', 'member_id', 'samity_id', 'loan_product_id', 'proposal_date',
        'loan_purpose', 'loan_amount', 'fdr_account_id', 'frequency',
        'no_of_installments', 'installment_amount', 'profit_rate',
        'profit_amount', 'total_repayment', 'installment_from_date',
        'installment_expire_date', 'guarantors', 'status',
        'created_by', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'proposal_date' => 'date', 'installment_from_date' => 'date',
            'installment_expire_date' => 'date', 'decided_at' => 'datetime',
            'loan_amount' => 'decimal:2', 'installment_amount' => 'decimal:2',
            'profit_rate' => 'decimal:2', 'profit_amount' => 'decimal:2',
            'total_repayment' => 'decimal:2', 'guarantors' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function samity(): BelongsTo
    {
        return $this->belongsTo(Samity::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fdrAccount(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'fdr_account_id');
    }
}
