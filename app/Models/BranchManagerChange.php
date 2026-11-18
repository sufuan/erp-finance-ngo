<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** History row for a Branch Manager Change (mirrors SamityManagerChange). */
class BranchManagerChange extends Model
{
    protected $table = 'branch_manager_changes';

    protected $fillable = [
        'branch_id', 'change_date', 'effective_from', 'previous_value',
        'new_value', 'comments', 'created_by',
    ];

    protected function casts(): array
    {
        return ['change_date' => 'date', 'effective_from' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
