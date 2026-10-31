<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringSetup extends Model
{
    protected $fillable = [
        'branch_id', 'name', 'debit_ledger_id', 'credit_ledger_id', 'amount',
        'frequency', 'next_run_date', 'last_run_date', 'last_voucher_id', 'is_active', 'entry_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'next_run_date' => 'date', 'last_run_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function debitLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'debit_ledger_id');
    }

    public function creditLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'credit_ledger_id');
    }
}
