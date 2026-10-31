<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerGroupLine extends Model
{
    protected $fillable = ['group_id', 'ledger_id', 'side'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(LedgerGroup::class, 'group_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
