<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Country;
use App\Models\District;
use App\Models\Division;
use App\Models\Union;
use App\Models\Upazila;
use App\Models\Village;
use App\Models\WorkingArea;
use Illuminate\Database\Seeder;

/**
 * Bangladesh geography (Step 4): all 8 divisions + the Rajshahi →
 * Sirajganj → Shahjadpur → Talgachi chain TPDA operates in, then the
 * branch geo FKs are backfilled from the chain (strings kept as fallback).
 */
class GeoSeeder extends Seeder
{
    public function run(): void
    {
        $bangladesh = Country::updateOrCreate(['name' => 'Bangladesh']);

        $divisions = ['Barishal', 'Chattogram', 'Dhaka', 'Khulna', 'Mymensingh', 'Rajshahi', 'Rangpur', 'Sylhet'];
        $rajshahi = null;
        foreach ($divisions as $name) {
            $division = Division::updateOrCreate(['name' => $name, 'country_id' => $bangladesh->id], ['country_id' => $bangladesh->id]);
            if ($name === 'Rajshahi') {
                $rajshahi = $division;
            }
        }

        // Districts of Rajshahi division TPDA may expand into.
        $sirajganj = null;
        foreach (['Sirajganj', 'Pabna', 'Bogura', 'Rajshahi', 'Natore', 'Naogaon', 'Chapainawabganj', 'Joypurhat'] as $name) {
            $district = District::updateOrCreate(['name' => $name, 'division_id' => $rajshahi->id], ['division_id' => $rajshahi->id]);
            if ($name === 'Sirajganj') {
                $sirajganj = $district;
            }
        }

        $shahjadpur = null;
        foreach (['Belkuchi', 'Chowhali', 'Kamarkhanda', 'Kazipur', 'Raiganj', 'Shahjadpur', 'Sirajganj Sadar', 'Tarash', 'Ullapara'] as $name) {
            $upazila = Upazila::updateOrCreate(['name' => $name, 'district_id' => $sirajganj->id], ['district_id' => $sirajganj->id]);
            if ($name === 'Shahjadpur') {
                $shahjadpur = $upazila;
            }
        }

        $talgachiUnion = null;
        foreach (['Gala', 'Kayempur', 'Porjana', 'Rupbati', 'Sonatoni', 'Talgachi'] as $name) {
            $union = Union::updateOrCreate(['name' => $name, 'upazila_id' => $shahjadpur->id], ['upazila_id' => $shahjadpur->id]);
            if ($name === 'Talgachi') {
                $talgachiUnion = $union;
            }
        }

        $talgachiVillage = Village::updateOrCreate(
            ['name' => 'Talgachi', 'union_id' => $talgachiUnion->id],
            ['upazila_id' => $shahjadpur->id]
        );

        $workingArea = WorkingArea::updateOrCreate(
            ['name' => 'Talgachi Working Area'],
            [
                'division_id' => $rajshahi->id, 'district_id' => $sirajganj->id,
                'upazila_id' => $shahjadpur->id, 'union_id' => $talgachiUnion->id,
                'village_id' => $talgachiVillage->id,
            ]
        );

        // Backfill branch geo FKs: match existing string columns where set;
        // the Talgachi branch is pinned to the TPDA chain directly.
        foreach (Branch::all() as $branch) {
            $updates = [];
            if ($branch->district) {
                $district = District::where('name', $branch->district)->first();
                if ($district) {
                    $updates['district_id'] = $district->id;
                    $updates['division_id'] = $district->division_id;
                    $updates['country_id'] = $district->division->country_id;
                }
            }
            if ($branch->code === '102') {
                $updates += [
                    'country_id' => $bangladesh->id, 'division_id' => $rajshahi->id,
                    'district_id' => $sirajganj->id, 'upazila_id' => $shahjadpur->id,
                    'union_id' => $talgachiUnion->id,
                    'division' => 'Rajshahi', 'district' => 'Sirajganj',
                    'upazila' => 'Shahjadpur', 'union' => 'Talgachi',
                ];
            }
            if ($updates) {
                $branch->update($updates);
            }
        }
    }
}
