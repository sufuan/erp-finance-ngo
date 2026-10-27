<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 23 — member passbook issue record (fee posts via PostingService). */
class PassbookIssue extends Model
{
    protected $fillable = [
        'branch_id', 'member_id', 'book_number', 'fee_amount', 'issue_date',
        'business_date', 'document_number', 'voucher_id', 'entry_by',
        'entry_at', 'cancel_by', 'cancel_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2', 'issue_date' => 'date',
            'business_date' => 'date', 'entry_at' => 'datetime',
            'cancel_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
