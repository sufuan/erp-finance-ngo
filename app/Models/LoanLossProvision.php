<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Step 20 — one LLP run; posting it moves the 1120 provision balance to required. */
class LoanLossProvision extends Model
{
    protected $fillable = [
        'account_type', 'report_date', 'outstanding', 'required_llp',
        'actual_llp', 'coverage', 'voucher_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date', 'outstanding' => 'decimal:2',
            'required_llp' => 'decimal:2', 'actual_llp' => 'decimal:2',
            'coverage' => 'decimal:2',
        ];
    }
}
