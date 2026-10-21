<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 17 — one installment row of a loan schedule. */
class LoanSchedule extends Model
{
    protected $fillable = [
        'loan_account_id', 'installment_no', 'due_date',
        'principal_due', 'profit_due', 'total_due',
        'paid_principal', 'paid_profit', 'paid_penalty', 'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal_due' => 'decimal:2', 'profit_due' => 'decimal:2',
            'total_due' => 'decimal:2', 'paid_principal' => 'decimal:2',
            'paid_profit' => 'decimal:2', 'paid_penalty' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
