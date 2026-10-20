<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Country;
use App\Models\District;
use App\Models\Union;
use App\Models\Upazila;
use App\Models\Village;
use App\Models\WorkingArea;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Plan Step 4 acceptance: full chain creation + cascading + seed chain. */
class GeoTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $admin = User::where('username', 'admin')->first();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $admin;
    }

    public function test_seed_chain_and_branch_backfill_exist(): void
    {
        $talgachi = Village::where('name', 'Talgachi')->first();
        $this->assertNotNull($talgachi);
        $this->assertSame('Talgachi', $talgachi->union->name);
        $this->assertSame('Shahjadpur', $talgachi->upazila->name);
        $this->assertSame('Shahjadpur', $talgachi->union->upazila->name);
        $this->assertSame('Sirajganj', $talgachi->upazila->district->name);
        $this->assertSame('Rajshahi', $talgachi->upazila->district->division->name);
        $this->assertSame('Bangladesh', $talgachi->upazila->district->division->country->name);
        $this->assertSame(8, \App\Models\Division::count());

        $branch = Branch::where('code', '102')->first();
        $this->assertSame($talgachi->union->id, $branch->union_id);
        $this->assertSame($talgachi->upazila->id, $branch->upazila_id);
        $this->assertSame('Sirajganj', $branch->district);

        $area = WorkingArea::where('name', 'Talgachi Working Area')->first();
        $this->assertSame($talgachi->id, $area->village_id);
    }

    public function test_full_chain_can_be_created_via_endpoints(): void
    {
        $this->admin();
        $country = Country::where('name', 'Bangladesh')->first();

        $this->post('/settings/geo/divisions', ['name' => 'Test Division', 'country_id' => $country->id])->assertSessionHas('success');
        $division = \App\Models\Division::where('name', 'Test Division')->first();
        $this->post('/settings/geo/districts', ['name' => 'Test District', 'division_id' => $division->id])->assertSessionHas('success');
        $district = District::where('name', 'Test District')->first();
        $this->post('/settings/geo/upazilas', ['name' => 'Test Upazila', 'district_id' => $district->id])->assertSessionHas('success');
        $upazila = Upazila::where('name', 'Test Upazila')->first();
        $this->post('/settings/geo/unions', ['name' => 'Test Union', 'upazila_id' => $upazila->id])->assertSessionHas('success');
        $union = Union::where('name', 'Test Union')->first();

        // Village upazila is derived from the union server-side.
        $this->post('/settings/geo/villages', ['name' => 'Test Village', 'union_id' => $union->id])->assertSessionHas('success');
        $village = Village::where('name', 'Test Village')->first();
        $this->assertSame($upazila->id, $village->upazila_id);

        $this->post('/settings/geo/working-areas', [
            'name' => 'Test Area', 'division_id' => $division->id, 'district_id' => $district->id,
            'upazila_id' => $upazila->id, 'union_id' => $union->id, 'village_id' => $village->id,
        ])->assertSessionHas('success');
        $this->assertNotNull(WorkingArea::where('name', 'Test Area')->first());
    }

    public function test_working_area_chain_mismatch_is_rejected(): void
    {
        $this->admin();
        $sirajganj = District::where('name', 'Sirajganj')->first();
        $otherDistrict = District::where('name', 'Pabna')->first();
        $response = $this->post('/settings/geo/working-areas', [
            'name' => 'Broken Area', 'district_id' => $otherDistrict->id,
            'upazila_id' => Upazila::where('district_id', $sirajganj->id)->first()->id,
        ]);
        $response->assertStatus(422);
        $this->assertNull(WorkingArea::where('name', 'Broken Area')->first());
    }

    public function test_parent_with_children_cannot_be_deleted(): void
    {
        $this->admin();
        $bangladesh = Country::where('name', 'Bangladesh')->first();
        $this->delete("/settings/geo/countries/{$bangladesh->id}")->assertSessionHas('error');
        $this->assertNotNull(Country::find($bangladesh->id));
    }

    public function test_geo_pages_require_permission_and_paginate(): void
    {
        $officer = User::factory()->create(['must_change_password' => false]);
        $officer->assignRole('field-officer');
        $this->actingAs($officer)->get('/settings/geo/districts')->assertForbidden();

        $this->admin();
        $this->get('/settings/geo/districts?per_page=25')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Geo/Index')
                ->where('entity', 'districts')
                ->where('items.per_page', 25)
                ->has('parentFields', 1));
    }
}
