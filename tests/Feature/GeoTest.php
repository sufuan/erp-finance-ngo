<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Village;
use App\Models\WorkingArea;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Geography after the bd-geo-info rework: divisions/districts/upazilas/
 * unions are NOT database data (the browser reads them from the npm
 * package); only villages + working areas remain as manual masters,
 * storing the selected package chain as plain values (id string + name).
 */
class GeoTest extends TestCase
{
    use DatabaseTransactions;

    /** A real bd-geo-info chain: Dhaka division (3) → Dhaka district (47) → Dhamrai (366) → Amta union (3284). */
    private const CHAIN = [
        'division_id' => '3', 'division_name' => 'Dhaka',
        'district_id' => '47', 'district_name' => 'Dhaka',
        'upazila_id' => '366', 'upazila_name' => 'Dhamrai',
        'union_id' => '3284', 'union_name' => 'Amta',
    ];

    private function admin(): User
    {
        $admin = User::where('username', 'admin')->first();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $admin;
    }

    public function test_geo_master_tables_are_gone(): void
    {
        foreach (['countries', 'divisions', 'districts', 'upazilas', 'unions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should have been dropped");
        }
        $this->assertTrue(Schema::hasTable('villages'));
        $this->assertTrue(Schema::hasTable('working_areas'));
        // No geo foreign keys anywhere: branches hold plain string ids.
        $this->assertFalse(Schema::hasColumn('branches', 'country_id'));
    }

    public function test_removed_geo_master_routes_are_404(): void
    {
        $this->admin();
        foreach (['countries', 'divisions', 'districts', 'upazilas', 'unions'] as $entity) {
            $this->get("/settings/geo/{$entity}")->assertNotFound();
            $this->post("/settings/geo/{$entity}", ['name' => 'X'])->assertNotFound();
        }
    }

    public function test_seed_village_and_working_area_use_plain_package_values(): void
    {
        $talgachi = Village::where('name', 'Talgachi')->first();
        $this->assertNotNull($talgachi);
        $this->assertSame('6', $talgachi->division_id);
        $this->assertSame('Rajshahi', $talgachi->division_name);
        $this->assertSame('12', $talgachi->district_id);
        $this->assertSame('Sirajganj', $talgachi->district_name);
        $this->assertSame('109', $talgachi->upazila_id);
        $this->assertSame('Shahjadpur', $talgachi->upazila_name);
        $this->assertSame('Talgachi', $talgachi->union_name);

        $area = WorkingArea::where('name', 'Talgachi Working Area')->first();
        $this->assertNotNull($area);
        $this->assertSame($talgachi->id, $area->village_id);
        $this->assertSame('Sirajganj', $area->district_name);

        $branch = Branch::where('code', '102')->first();
        $this->assertSame('6', $branch->division_id);
        $this->assertSame('12', $branch->district_id);
        $this->assertSame('Sirajganj', $branch->district);
    }

    public function test_village_crud_with_package_chain(): void
    {
        $this->admin();
        $this->post('/settings/geo/villages', ['name' => 'Test Village', ...self::CHAIN])
            ->assertSessionHas('success');
        $village = Village::where('name', 'Test Village')->first();
        $this->assertNotNull($village);
        $this->assertSame('366', $village->upazila_id);
        $this->assertSame('Amta', $village->union_name);

        $this->put("/settings/geo/villages/{$village->id}", [
            'name' => 'Renamed Village', ...self::CHAIN, 'union_name' => 'Baliati',
        ])->assertSessionHas('success');
        $this->assertSame('Baliati', $village->fresh()->union_name);

        $this->delete("/settings/geo/villages/{$village->id}")->assertSessionHas('success');
        $this->assertNull(Village::find($village->id));
    }

    public function test_village_requires_the_full_chain(): void
    {
        $this->admin();
        $payload = ['name' => 'Broken Village', ...self::CHAIN];
        unset($payload['union_id'], $payload['union_name']);
        $this->post('/settings/geo/villages', $payload)
            ->assertSessionHasErrors(['union_id', 'union_name']);
        $this->assertNull(Village::where('name', 'Broken Village')->first());
    }

    public function test_working_area_crud_chain_optional_village_linked(): void
    {
        $this->admin();
        // Everything but the name is optional for a working area.
        $this->post('/settings/geo/working-areas', ['name' => 'Bare Area'])->assertSessionHas('success');
        $this->assertNotNull(WorkingArea::where('name', 'Bare Area')->first());

        $village = Village::where('name', 'Talgachi')->first();
        $this->post('/settings/geo/working-areas', [
            'name' => 'Full Area', ...self::CHAIN, 'village_id' => $village->id,
        ])->assertSessionHas('success');
        $area = WorkingArea::where('name', 'Full Area')->first();
        $this->assertSame('Dhamrai', $area->upazila_name);
        $this->assertSame($village->id, $area->village_id);
        $this->assertSame('Talgachi', $area->village->name);
    }

    public function test_branch_create_with_package_geo_values_persists(): void
    {
        $this->admin();
        $this->post('/settings/branches', [
            'code' => '900', 'name' => 'Geo Branch', 'type' => 'Branch', 'status' => 'Active',
            'division' => 'Rajshahi', 'district' => 'Sirajganj',
            'upazila' => 'Shahjadpur', 'union' => 'Gala',
            'division_id' => '6', 'district_id' => '12', 'upazila_id' => '109', 'union_id' => '995',
        ])->assertSessionHas('success');

        $branch = Branch::where('code', '900')->first();
        $this->assertNotNull($branch);
        $this->assertSame('Sirajganj', $branch->district);
        $this->assertSame('6', $branch->division_id);
        $this->assertSame('995', $branch->union_id);
    }

    public function test_geo_pages_require_permission_and_render(): void
    {
        $officer = User::factory()->create(['must_change_password' => false]);
        $officer->assignRole('field-officer');
        $this->actingAs($officer)->get('/settings/geo/villages')->assertForbidden();

        $this->admin();
        $this->get('/settings/geo/villages?per_page=25')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Geo/Index')
                ->where('entity', 'villages')
                ->where('items.per_page', 25)
                ->has('parentFields', 0)
                ->has('entities', 2));
        $this->get('/settings/geo/working-areas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Geo/Index')
                ->where('entity', 'working-areas')
                ->has('parentFields', 1));
    }
}
