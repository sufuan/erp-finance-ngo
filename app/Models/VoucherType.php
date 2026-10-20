<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherType extends Model
{
    protected $fillable = ['code', 'require_authorization', 'label_en', 'label_bn'];

    protected function casts(): array
    {
        return ['require_authorization' => 'boolean'];
    }
}
