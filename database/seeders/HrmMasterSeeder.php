<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeType;
use App\Models\Shift;
use Illuminate\Database\Seeder;

/** HR starter masters (Step 5) — TPDA-typical values, editable in the UI. */
class HrmMasterSeeder extends Seeder
{
    public function run(): void
    {
        $head = Branch::where('code', '101')->first();
        $talgachi = Branch::where('code', '102')->first();

        foreach (['Accounts', 'Microfinance Operations', 'Human Resources', 'Administration', 'Information Technology'] as $name) {
            Department::updateOrCreate(['branch_id' => $head->id, 'name' => $name], ['is_active' => true]);
        }
        foreach (['Microfinance Operations', 'Accounts'] as $name) {
            Department::updateOrCreate(['branch_id' => $talgachi->id, 'name' => $name], ['is_active' => true]);
        }

        foreach (['Executive Director', 'Program Manager', 'Branch Manager', 'Accountant', 'Field Officer', 'Office Assistant', 'Driver'] as $name) {
            Designation::updateOrCreate(['name' => $name], ['is_active' => true]);
        }

        foreach (['Permanent', 'Probation', 'Contractual', 'Part-time'] as $name) {
            EmployeeType::updateOrCreate(['name' => $name], ['is_active' => true]);
        }

        Shift::updateOrCreate(['name' => 'General Shift'], [
            'start_time' => '09:00', 'end_time' => '17:00', 'grace_minutes' => 10,
            'refresh_start' => '13:00', 'refresh_end' => '14:00',
            'overtime_start' => '17:00', 'overtime_end' => '20:00',
            'monthly_duty_days' => 26, 'daily_duty_minutes' => 480, 'allowed_late_days' => 3,
            'default_bonus_percent' => 0, 'default_deduction_percent' => 0, 'is_active' => true,
        ]);
    }
}
