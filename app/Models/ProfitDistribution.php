<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Step 13 — one profit distribution run for a samity over a date range. */
class ProfitDistribution extends Model
{
    protected $fillable = [
        'branch_id', 'samity_id', 'account_type', 'from_date', 'to_date',
        'profit_basis', 'profit_rate', 'distribution_amount', 'posting_date',
        'exclude_zero_balance', 'status', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date', 'to_date' => 'date', 'posting_date' => 'date',
            'profit_rate' => 'decimal:2', 'distribution_amount' => 'decimal:2',
            'exclude_zero_balance' => 'boolean', 'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProfitDistributionLine::class, 'distribution_id');
    }

    public function samity(): BelongsTo
    {
        return $this->belongsTo(Samity::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
