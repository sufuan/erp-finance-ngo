<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 12 — a hold on a deposit account (deposit and/or withdrawal side). */
class DepositBlock extends Model
{
    protected $fillable = [
        'account_id', 'block_effective_date', 'block_amount', 'reason',
        'deposit_block', 'withdrawal_block', 'block_by', 'block_at',
        'clear_date', 'clear_amount', 'clear_reason', 'cleared_by', 'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'block_effective_date' => 'date',
            'clear_date' => 'date',
            'block_amount' => 'decimal:2',
            'clear_amount' => 'decimal:2',
            'deposit_block' => 'boolean',
            'withdrawal_block' => 'boolean',
            'block_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'account_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('clear_date');
    }
}
