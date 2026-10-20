<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeType;
use App\Services\NumberingService;
use Illuminate\Database\Seeder;

/**
 * Step 6 — starter staff so officer/manager pickers in Steps 7+ have
 * real people: TPDA contact Md. Mostafa (Executive Director, HO),
 * a Talgachi branch manager and two field officers.
 */
class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $head = Branch::where('code', '101')->first();
        $talgachi = Branch::where('code', '102')->first();
        $permanent = EmployeeType::where('name', 'Permanent')->first();
        $numbering = app(NumberingService::class);

        $make = function (array $attrs) use ($numbering, $head) {
            $existing = Employee::where('mobile', $attrs['mobile'])->first();
            if ($existing) {
                return $existing;
            }
            $attrs['employee_code'] = str_pad((string) $numbering->next($head->id, 'employee'), 6, '0', STR_PAD_LEFT);

            return Employee::create($attrs);
        };

        $dept = fn (int $branchId, string $name) => Department::where('branch_id', $branchId)->where('name', $name)->first();
        $desig = fn (string $name) => Designation::where('name', $name)->first();

        $mostafa = $make([
            'branch_id' => $head->id, 'name' => 'Md. Mostafa', 'gender' => 'Male',
            'joining_date' => '2000-05-01', 'department_id' => $dept($head->id, 'Administration')?->id,
            'designation_id' => $desig('Executive Director')?->id, 'employee_type_id' => $permanent?->id,
            'national_id' => '1975123456', 'mobile' => '01711111111', 'status' => 'Active',
        ]);

        $manager = $make([
            'branch_id' => $talgachi->id, 'name' => 'Md. Abdul Karim', 'gender' => 'Male',
            'joining_date' => '2015-03-01', 'department_id' => $dept($talgachi->id, 'Microfinance Operations')?->id,
            'designation_id' => $desig('Branch Manager')?->id, 'employee_type_id' => $permanent?->id,
            'national_id' => '1980234567', 'mobile' => '01812222222', 'status' => 'Active',
        ]);

        $make([
            'branch_id' => $talgachi->id, 'name' => 'Md. Rofiqul Islam', 'gender' => 'Male',
            'joining_date' => '2019-06-15', 'department_id' => $dept($talgachi->id, 'Microfinance Operations')?->id,
            'designation_id' => $desig('Field Officer')?->id, 'employee_type_id' => $permanent?->id,
            'manager_id' => $manager->id,
            'national_id' => '1990345678', 'mobile' => '01913333333', 'status' => 'Active',
        ]);

        $make([
            'branch_id' => $talgachi->id, 'name' => 'Mst. Sharmin Akter', 'gender' => 'Female',
            'joining_date' => '2021-01-10', 'department_id' => $dept($talgachi->id, 'Microfinance Operations')?->id,
            'designation_id' => $desig('Field Officer')?->id, 'employee_type_id' => $permanent?->id,
            'manager_id' => $manager->id,
            'national_id' => '1992456789', 'mobile' => '01614444444', 'status' => 'Active',
        ]);

        // Branch manager FK (enforced in Step 6) + department in-charge.
        $talgachi->update(['manager_id' => $manager->id]);
        $dept($talgachi->id, 'Microfinance Operations')?->update(['incharge_id' => $manager->id]);
        $mostafa->update(['manager_id' => null]);
    }
}
