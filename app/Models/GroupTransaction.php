<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupTransaction extends Model
{
    protected $fillable = ['branch_id', 'group_id', 'voucher_id', 'entry_by'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(LedgerGroup::class, 'group_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entry_by');
    }
}
