<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 19 — loan write-off against the LLP provision (mapping loan.writeoff). */
class Writeoff extends Model
{
    protected $table = 'writeoffs';

    protected $fillable = [
        'loan_account_id', 'writeoff_date', 'total_amount', 'principal',
        'profit', 'charge', 'comments', 'entry_by', 'entry_at',
    ];

    protected function casts(): array
    {
        return [
            'writeoff_date' => 'date', 'total_amount' => 'decimal:2',
            'principal' => 'decimal:2', 'profit' => 'decimal:2', 'charge' => 'decimal:2',
            'entry_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
