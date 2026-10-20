<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlMapping extends Model
{
    protected $fillable = ['mapping_key', 'debit_ledger_id', 'credit_ledger_id', 'description'];

    public function debitLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'debit_ledger_id');
    }

    public function creditLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'credit_ledger_id');
    }

    public static function byKey(string $key): ?self
    {
        return static::where('mapping_key', $key)->first();
    }
}
