<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 11 — product detail of one deposit receive/payment. */
class DepositTransaction extends Model
{
    protected $fillable = [
        'branch_id', 'account_id', 'member_id', 'tran_type', 'product',
        'amount', 'tran_date', 'business_date', 'document_number', 'comments',
        'voucher_id', 'entry_by', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tran_date' => 'date',
            'business_date' => 'date',
            'cancel_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'account_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
