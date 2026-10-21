<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Samity;
use App\Models\WorkingArea;
use App\Services\NumberingService;
use Illuminate\Database\Seeder;

/** Step 7 — two starter samities under the Talgachi working area. */
class SamitySeeder extends Seeder
{
    public function run(): void
    {
        $head = Branch::where('code', '101')->first();
        $talgachi = Branch::where('code', '102')->first();
        $area = WorkingArea::where('name', 'like', '%Talgachi%')->first();
        $numbering = app(NumberingService::class);

        $officer1 = Employee::where('mobile', '01913333333')->first();
        $officer2 = Employee::where('mobile', '01614444444')->first();
        $manager = Employee::where('mobile', '01812222222')->first();

        $make = function (array $attrs) use ($numbering, $head) {
            $existing = Samity::withoutGlobalScopes()->where('name', $attrs['name'])->first();
            if ($existing) {
                return $existing;
            }
            $attrs['samity_code'] = str_pad((string) $numbering->next($head->id, 'samity'), 4, '0', STR_PAD_LEFT);

            return Samity::create($attrs);
        };

        $make([
            'branch_id' => $talgachi->id, 'name' => 'Talgachi Samity',
            'address' => 'Talgachi, Shahjadpur, Sirajganj', 'open_date' => '2020-01-05',
            'working_area_id' => $area?->id, 'field_officer_id' => $officer1?->id,
            'center_type' => 'All Gender', 'start_time' => '09:00', 'samity_day' => 'Wednesday',
            'manager_id' => $manager?->id, 'status' => 'Active',
        ]);

        $make([
            'branch_id' => $talgachi->id, 'name' => 'Talgachi Mohila Samity',
            'address' => 'Talgachi, Shahjadpur, Sirajganj', 'open_date' => '2020-02-02',
            'working_area_id' => $area?->id, 'field_officer_id' => $officer2?->id,
            'center_type' => 'Female', 'start_time' => '10:00', 'samity_day' => 'Sunday',
            'manager_id' => $manager?->id, 'status' => 'Active',
        ]);
    }
}
