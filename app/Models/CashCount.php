<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCount extends Model
{
    protected $fillable = [
        'branch_id', 'user_id', 'business_date', 'expected_balance',
        'counted_balance', 'difference', 'entry_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date', 'expected_balance' => 'decimal:2',
            'counted_balance' => 'decimal:2', 'difference' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
