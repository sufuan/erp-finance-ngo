<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 13 — per-account profit rate, effective from a date until cancelled/superseded. */
class ProfitSetup extends Model
{
    protected $fillable = [
        'deposit_account_id', 'effective_date', 'serial', 'profit_rate',
        'setup_by', 'setup_at', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'profit_rate' => 'decimal:2',
            'setup_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'deposit_account_id');
    }
}
