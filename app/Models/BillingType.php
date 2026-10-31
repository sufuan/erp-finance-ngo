<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingType extends Model
{
    protected $fillable = ['name_en', 'name_bn', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function setups(): HasMany
    {
        return $this->hasMany(ReceivePaymentSetup::class);
    }
}
