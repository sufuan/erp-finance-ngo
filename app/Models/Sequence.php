<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    protected $fillable = ['branch_id', 'entity', 'prefix', 'last_number'];

    protected $casts = ['last_number' => 'integer'];
}
