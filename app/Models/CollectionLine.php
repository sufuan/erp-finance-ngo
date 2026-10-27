<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Step 21 — one collection line per target account. `ref_type`/`ref_id`
 * point at the product detail row the money engines created, so cancel
 * can delegate to the engines' latest-only rules.
 */
class CollectionLine extends Model
{
    protected $fillable = [
        'batch_id', 'member_id', 'account_type', 'account_id',
        'deposit_product_code', 'receivable', 'amount', 'ref_type',
        'ref_id', 'status', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'receivable' => 'decimal:2', 'amount' => 'decimal:2',
            'cancel_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CollectionBatch::class, 'batch_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function depositTransaction(): BelongsTo
    {
        return $this->belongsTo(DepositTransaction::class, 'ref_id');
    }

    public function loanReceive(): BelongsTo
    {
        return $this->belongsTo(LoanReceive::class, 'ref_id');
    }
}
