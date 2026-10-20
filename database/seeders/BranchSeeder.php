<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Services\BusinessDayService;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $head = Branch::updateOrCreate(
            ['code' => '101'],
            [
                'name' => 'Head Office',
                'type' => 'Head Office',
                'opening_date' => '2000-01-01',
                'country' => 'Bangladesh',
                'status' => 'Active',
            ]
        );

        $talgachi = Branch::updateOrCreate(
            ['code' => '102'],
            [
                'name' => 'Talgachi Branch',
                'type' => 'Branch',
                'parent_branch_id' => $head->id,
                'opening_date' => '2000-01-01',
                'country' => 'Bangladesh',
                'division' => 'Rajshahi',
                'district' => 'Sirajganj',
                'upazila' => 'Shahjadpur',
                'union' => 'Talgachi',
                'status' => 'Active',
            ]
        );

        $days = app(BusinessDayService::class);
        $days->current($head->id);
        $days->current($talgachi->id);
    }
}
