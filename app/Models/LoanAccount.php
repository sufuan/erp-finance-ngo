<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Step 17 — a booked loan. Lifecycle (binding): Pending → Approved →
 * Opened → Disbursed → Active → Closed, plus WrittenOff. Opening creates
 * status Opened; `profit_rate` is a snapshot of the product rate.
 */
class LoanAccount extends Model
{
    protected $fillable = [
        'loan_number', 'branch_id', 'member_id', 'employee_id', 'loan_product_id',
        'loan_proposal_id', 'opening_date', 'loan_purpose', 'loan_amount',
        'down_payment', 'block_account', 'frequency', 'no_of_installments',
        'installment_amount', 'actual_installment_amount', 'extra_installment_amount',
        'last_installment_amount', 'profit_rate', 'profit_amount', 'total_repayment',
        'installment_from_date', 'installment_expire_date', 'payment_type',
        'payment_to_account', 'invoice_id', 'invoice_number', 'status',
        'installment_reminder', 'overdue_reminder', 'disbursed_by', 'disbursed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date', 'installment_from_date' => 'date',
            'installment_expire_date' => 'date', 'disbursed_at' => 'datetime',
            'loan_amount' => 'decimal:2', 'down_payment' => 'decimal:2',
            'installment_amount' => 'decimal:2', 'actual_installment_amount' => 'decimal:2',
            'extra_installment_amount' => 'decimal:2', 'last_installment_amount' => 'decimal:2',
            'profit_rate' => 'decimal:2', 'profit_amount' => 'decimal:2',
            'total_repayment' => 'decimal:2',
            'block_account' => 'boolean', 'installment_reminder' => 'boolean',
            'overdue_reminder' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(LoanProposal::class, 'loan_proposal_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LoanSchedule::class);
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(LoanReschedule::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(LoanFee::class);
    }
}
