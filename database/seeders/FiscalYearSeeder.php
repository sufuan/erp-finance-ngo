<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\FiscalYear;
use Illuminate\Database\Seeder;

/** Seeds the open fiscal year per branch (Bangladesh FY: July–June). */
class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $startYear = $now->month >= 7 ? $now->year : $now->year - 1;
        $name = 'FY '.$startYear.'-'.substr((string) ($startYear + 1), -2);

        foreach (Branch::all() as $branch) {
            FiscalYear::updateOrCreate(
                ['branch_id' => $branch->id, 'name' => $name],
                ['start_date' => "{$startYear}-07-01", 'end_date' => ($startYear + 1).'-06-30', 'status' => 'Open']
            );
        }
    }
}
