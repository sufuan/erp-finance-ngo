<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Village;
use App\Models\WorkingArea;
use Illuminate\Database\Seeder;

/**
 * Geography seed (reworked 2026-10-21): divisions/districts/upazilas/
 * unions are no longer database data — the UI reads them from the
 * `bd-geo-info` npm package. This seeder only creates TPDA's manual
 * masters: the Talgachi village + working area, storing the package's
 * chain as plain values — Rajshahi division (id 6) → Sirajganj district
 * (id 12) → Shahjadpur upazila (id 109). The package has no 'Talgachi'
 * union under Shahjadpur, so the village keeps Talgachi as a plain
 * legacy union name with no package id. Branch 102 is pinned to the
 * same chain (name strings + package ids).
 */
class GeoSeeder extends Seeder
{
    public function run(): void
    {
        $chain = [
            'division_id' => '6', 'division_name' => 'Rajshahi',
            'district_id' => '12', 'district_name' => 'Sirajganj',
            'upazila_id' => '109', 'upazila_name' => 'Shahjadpur',
        ];

        $talgachiVillage = Village::updateOrCreate(
            ['name' => 'Talgachi'],
            [...$chain, 'union_id' => null, 'union_name' => 'Talgachi']
        );

        WorkingArea::updateOrCreate(
            ['name' => 'Talgachi Working Area'],
            [...$chain, 'union_id' => null, 'union_name' => 'Talgachi', 'village_id' => $talgachiVillage->id]
        );

        $talgachi = Branch::where('code', '102')->first();
        if ($talgachi) {
            // Branches store the names in their own string columns
            // (division/district/upazila/union), not *_name columns.
            $talgachi->update([
                'division_id' => $chain['division_id'], 'district_id' => $chain['district_id'],
                'upazila_id' => $chain['upazila_id'], 'union_id' => null,
                'division' => 'Rajshahi', 'district' => 'Sirajganj',
                'upazila' => 'Shahjadpur', 'union' => 'Talgachi',
            ]);
        }
    }
}
