<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 19 — a schedule regeneration; superseded rows point back here. */
class LoanReschedule extends Model
{
    protected $fillable = [
        'loan_account_id', 'reschedule_date', 'additional_profit', 'frequency',
        'installment_amount', 'no_of_installments', 'first_installment_date',
        'installment_expire_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reschedule_date' => 'date', 'first_installment_date' => 'date',
            'installment_expire_date' => 'date', 'additional_profit' => 'decimal:2',
            'installment_amount' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
