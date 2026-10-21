<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 17 — loan guarantor; national ID stored encrypted + SHA-256 hash (Step 6/8 pattern). */
class Guarantor extends Model
{
    protected $fillable = [
        'loan_account_id', 'name', 'relation', 'phone', 'address',
        'national_id', 'national_id_hash',
    ];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::saving(function (Guarantor $guarantor) {
            // The encrypted cast decrypts on access; hash the plain value
            // whenever national_id is dirty.
            if ($guarantor->isDirty('national_id')) {
                $value = $guarantor->national_id;
                $guarantor->national_id_hash = $value ? hash('sha256', preg_replace('/\D/', '', $value)) : null;
            }
        });
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
