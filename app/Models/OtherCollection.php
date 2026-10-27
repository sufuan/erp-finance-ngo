<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 21 — branch-level miscellaneous collection receipt. */
class OtherCollection extends Model
{
    protected $fillable = [
        'branch_id', 'member_id', 'collection_type', 'collection_account',
        'amount', 'tran_date', 'business_date', 'document_number',
        'comments', 'voucher_id', 'entry_by', 'entry_at', 'cancel_by',
        'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'tran_date' => 'date',
            'business_date' => 'date', 'entry_at' => 'datetime',
            'cancel_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
