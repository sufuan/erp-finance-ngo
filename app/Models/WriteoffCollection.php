<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 19 — cash recovered against a written-off loan. */
class WriteoffCollection extends Model
{
    protected $fillable = [
        'loan_account_id', 'recovery_date', 'total_recovery',
        'principal_recovery', 'profit_recovery', 'charge_recovery',
        'comments', 'entry_by', 'entry_at',
    ];

    protected function casts(): array
    {
        return [
            'recovery_date' => 'date', 'total_recovery' => 'decimal:2',
            'principal_recovery' => 'decimal:2', 'profit_recovery' => 'decimal:2',
            'charge_recovery' => 'decimal:2', 'entry_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
