<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 17 — per-loan penal charge setup (plan columns: updated_by/at only). */
class DuePenaltySetup extends Model
{
    public $timestamps = false;

    protected $fillable = ['loan_account_id', 'penal_charge', 'updated_by', 'updated_at'];

    protected function casts(): array
    {
        return ['penal_charge' => 'decimal:2', 'updated_at' => 'datetime'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }
}
