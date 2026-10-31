<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransfer extends Model
{
    protected $fillable = [
        'branch_id', 'from_user_id', 'to_user_id', 'amount', 'counted_amount', 'status',
        'business_date', 'voucher_id', 'variance_voucher_id', 'entry_by', 'received_by',
        'received_at', 'comments',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'counted_amount' => 'decimal:2',
            'business_date' => 'date', 'received_at' => 'datetime',
        ];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
