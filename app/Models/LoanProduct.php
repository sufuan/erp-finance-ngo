<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Step 15 — the loan product master. `profit_rate` here is THE rate
 * (single source of truth); see LoanRateGuard for the MRA cap rule.
 */
class LoanProduct extends Model
{
    protected $fillable = [
        'code', 'name_en', 'name_bn', 'profit_rate', 'rate_method',
        'min_amount', 'max_amount', 'min_term', 'max_term',
        'allowed_frequencies', 'penalty_rule', 'gl_mapping_prefix',
        'min_cycle', 'max_cycle', 'min_savings_balance',
        'max_concurrent_loans', 'min_member_months', 'fee_config', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'profit_rate' => 'decimal:2',
            'min_amount' => 'decimal:2', 'max_amount' => 'decimal:2',
            'min_savings_balance' => 'decimal:2',
            'allowed_frequencies' => 'array', 'penalty_rule' => 'array',
            'fee_config' => 'array', 'is_active' => 'boolean',
        ];
    }
}
