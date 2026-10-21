<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 11 — account-to-account deposit transfer (no cash leg). */
class DepositTransfer extends Model
{
    protected $fillable = [
        'branch_id', 'from_account_id', 'to_account_id', 'amount',
        'transfer_date', 'document_number', 'comments',
        'entry_by', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_date' => 'date',
            'cancel_at' => 'datetime',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'to_account_id');
    }
}
