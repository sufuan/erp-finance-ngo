<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Director register (Step 6) — same personal/bank profile shape as
 * employees, without branch scoping. Identity documents encrypted.
 */
class Director extends Model
{
    protected $fillable = [
        'name', 'father_name', 'mother_name', 'gender', 'present_address',
        'permanent_address', 'dob', 'national_id', 'nid_hash', 'passport_no',
        'passport_hash', 'tin', 'religion', 'blood_group', 'education',
        'marital_status', 'mobile', 'emergency_mobile', 'email', 'bank_name',
        'bank_account_number', 'bank_account_name', 'bank_branch_name',
        'branch_routing_number', 'bank_comments', 'photo_path', 'director_status',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'national_id' => 'encrypted',
            'passport_no' => 'encrypted',
            'tin' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Director $director) {
            $director->nid_hash = $director->national_id
                ? hash('sha256', preg_replace('/\D/', '', (string) $director->national_id))
                : null;
            $director->passport_hash = $director->passport_no
                ? hash('sha256', strtoupper(trim((string) $director->passport_no)))
                : null;
        });
    }
}
