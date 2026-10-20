<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationProfile extends Model
{
    protected $fillable = [
        'name_en', 'name_bn', 'org_type', 'registration_no', 'logo_path',
        'mobile_app_name', 'contact_person', 'address', 'phone', 'email', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
