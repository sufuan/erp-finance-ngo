<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Step 10 — the real deposit product master. All product behaviour
 * (rates, minimums, maturity, penalties, structured config such as the
 * Long Term Savings table) is data on this row, editable by the admin —
 * nothing is hard-coded in the account engine.
 */
class DepositProduct extends Model
{
    protected $fillable = [
        'code', 'name_en', 'name_bn', 'is_compulsory', 'default_profit_rate',
        'minimum_balance', 'minimum_opening_amount', 'withdrawal_rules',
        'maturity_months', 'premature_penalty_rate', 'auto_renew',
        'gl_mapping_prefix', 'config', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_compulsory' => 'boolean',
            'auto_renew' => 'boolean',
            'is_active' => 'boolean',
            'default_profit_rate' => 'decimal:2',
            'minimum_balance' => 'decimal:2',
            'minimum_opening_amount' => 'decimal:2',
            'premature_penalty_rate' => 'decimal:2',
            'withdrawal_rules' => 'array',
            'config' => 'array',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(DepositAccount::class);
    }
}
