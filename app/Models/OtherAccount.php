<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Step 10 — generic GL-style account opened outside the member register. */
class OtherAccount extends Model
{
    protected $fillable = [
        'account_type', 'name', 'account_number', 'opening_date',
        'balance', 'comments', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
            'balance' => 'decimal:2',
        ];
    }
}
