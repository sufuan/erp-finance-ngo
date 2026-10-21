<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 18 — how one receipt was split across schedule rows (for exact reversal). */
class LoanReceiveAllocation extends Model
{
    protected $fillable = ['loan_receive_id', 'loan_schedule_id', 'principal', 'profit', 'penalty'];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2', 'profit' => 'decimal:2', 'penalty' => 'decimal:2',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LoanSchedule::class, 'loan_schedule_id');
    }
}
