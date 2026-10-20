<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ledger extends Model
{
    protected $fillable = ['ledger_code', 'name_en', 'name_bn', 'ledger_group', 'parent_id', 'is_control', 'is_active'];

    protected function casts(): array
    {
        return ['is_control' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Ledger::class, 'parent_id');
    }

    public static function byCode(string $code): ?self
    {
        return static::where('ledger_code', $code)->first();
    }
}
