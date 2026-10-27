<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Step 21 — one collection posting action (member / sheet / simple). */
class CollectionBatch extends Model
{
    protected $fillable = [
        'branch_id', 'samity_id', 'member_id', 'kind', 'tran_date',
        'business_date', 'total_receivable', 'loan_receive',
        'deposit_receive', 'share_receive', 'total_receive', 'total_dues',
        'status', 'entry_by', 'entry_at', 'cancel_by', 'cancel_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'tran_date' => 'date', 'business_date' => 'date',
            'total_receivable' => 'decimal:2', 'loan_receive' => 'decimal:2',
            'deposit_receive' => 'decimal:2', 'share_receive' => 'decimal:2',
            'total_receive' => 'decimal:2', 'total_dues' => 'decimal:2',
            'entry_at' => 'datetime', 'cancel_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function samity(): BelongsTo
    {
        return $this->belongsTo(Samity::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CollectionLine::class, 'batch_id');
    }

    public function entryBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entry_by');
    }
}
