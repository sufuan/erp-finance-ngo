<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 19 — additional EMI penalty: extra penalty + optional extra profit. */
class LoanPenalty extends Model
{
    protected $fillable = [
        'loan_account_id', 'entry_date', 'additional_profit', 'penalty_amount',
        'schedule_id', 'comments', 'entry_by', 'entry_at', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'additional_profit' => 'decimal:2', 'penalty_amount' => 'decimal:2',
            'entry_at' => 'datetime', 'cancel_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
