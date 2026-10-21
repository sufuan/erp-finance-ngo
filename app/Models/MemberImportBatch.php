<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step 9A — one member-migration run (dry-run staging + import result). */
class MemberImportBatch extends Model
{
    protected $fillable = [
        'created_by', 'samity_id', 'source', 'status', 'total_rows',
        'imported_count', 'failed_count', 'rows', 'failures',
    ];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'failures' => 'array',
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
