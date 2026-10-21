<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 20A — one balance-migration run (dry-run staging + import result). */
class BalanceImportBatch extends Model
{
    protected $fillable = [
        'created_by', 'samity_id', 'branch_id', 'transaction_date', 'add_members',
        'source', 'status', 'total_rows', 'imported_count', 'failed_count',
        'rows', 'failures',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date', 'add_members' => 'boolean',
            'rows' => 'array', 'failures' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function samity(): BelongsTo
    {
        return $this->belongsTo(Samity::class);
    }
}
