<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 15 — an installment option (frequency × count × rate) for a loan product. */
class EmiOptionTemplate extends Model
{
    protected $fillable = [
        'loan_product_id', 'frequency', 'no_of_installments',
        'profit_rate', 'comments', 'is_active',
    ];

    protected function casts(): array
    {
        return ['profit_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}
