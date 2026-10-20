<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee register (Step 6). HR profile only — login accounts live in
 * `users` and link via users.employee_id. NID/passport/TIN are encrypted
 * at rest; hashes allow duplicate checks without decryption.
 */
class Employee extends Model
{
    protected $fillable = [
        'employee_code', 'branch_id', 'name', 'father_name', 'mother_name', 'gender',
        'joining_date', 'department_id', 'designation_id', 'employee_type_id', 'manager_id',
        'present_address', 'permanent_address', 'dob', 'national_id', 'nid_hash',
        'passport_no', 'passport_hash', 'tin', 'religion', 'blood_group', 'education',
        'marital_status', 'mobile', 'emergency_mobile', 'email', 'bank_name',
        'bank_account_number', 'bank_account_name', 'bank_branch_name',
        'branch_routing_number', 'bank_comments', 'photo_path', 'status',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'dob' => 'date',
            'national_id' => 'encrypted',
            'passport_no' => 'encrypted',
            'tin' => 'encrypted',
        ];
    }

    /** Keep hash columns in sync with the encrypted identity documents. */
    protected static function booted(): void
    {
        static::saving(function (Employee $employee) {
            $employee->nid_hash = $employee->national_id
                ? hash('sha256', preg_replace('/\D/', '', (string) $employee->national_id))
                : null;
            $employee->passport_hash = $employee->passport_no
                ? hash('sha256', strtoupper(trim((string) $employee->passport_no)))
                : null;
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function employeeType(): BelongsTo
    {
        return $this->belongsTo(EmployeeType::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }
}
