<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 13 — per-account profit line of a distribution run. */
class ProfitDistributionLine extends Model
{
    protected $fillable = [
        'distribution_id', 'account_type', 'account_id',
        'balance', 'profit_on_amount', 'profit_pending', 'total_payable',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2', 'profit_on_amount' => 'decimal:2',
            'profit_pending' => 'decimal:2', 'total_payable' => 'decimal:2',
        ];
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(ProfitDistribution::class, 'distribution_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'account_id');
    }
}
