<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    protected $fillable = [
        'branch_id', 'batch_number', 'voucher_type', 'transaction_date', 'business_date',
        'total_amount', 'comments', 'document_path', 'entry_by', 'entry_at',
        'auth_by', 'auth_at', 'cancel_by', 'cancel_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date', 'business_date' => 'date',
            'total_amount' => 'decimal:2',
            'entry_at' => 'datetime', 'auth_at' => 'datetime', 'cancel_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class);
    }

    public function accountTransactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entry_by');
    }
}
