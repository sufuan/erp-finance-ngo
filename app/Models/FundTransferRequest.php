<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundTransferRequest extends Model
{
    protected $fillable = [
        'request_no', 'kind', 'from_branch_id', 'to_branch_id', 'amount', 'item_name',
        'quantity', 'comments', 'status', 'requested_by', 'approved_by', 'approved_at',
        'received_by', 'received_at', 'send_voucher_id', 'receive_voucher_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'quantity' => 'decimal:2',
            'approved_at' => 'datetime', 'received_at' => 'datetime',
        ];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function sendVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'send_voucher_id');
    }

    public function receiveVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'receive_voucher_id');
    }
}
