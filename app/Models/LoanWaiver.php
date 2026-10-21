<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 19 — forgiveness of principal / profit / charge, exactly per splits. */
class LoanWaiver extends Model
{
    protected $fillable = [
        'loan_account_id', 'waiver_date', 'principal_waive', 'profit_waive',
        'charge_waive', 'total_waiver', 'row_splits', 'comments',
        'entry_by', 'entry_at', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'waiver_date' => 'date',
            'principal_waive' => 'decimal:2', 'profit_waive' => 'decimal:2',
            'charge_waive' => 'decimal:2', 'total_waiver' => 'decimal:2',
            'row_splits' => 'array',
            'entry_at' => 'datetime', 'cancel_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
