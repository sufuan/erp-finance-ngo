<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberDocument extends Model
{
    protected $table = 'member_documents';

    protected $fillable = ['member_id', 'doc_type', 'file_path', 'created_by'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
