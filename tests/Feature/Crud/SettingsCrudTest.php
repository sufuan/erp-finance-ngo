<?php

namespace Tests\Feature\Crud;

use App\Models\Branch;
use App\Models\BusinessDay;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Notice;
use App\Models\OrganizationProfile;
use App\Models\Samity;
use App\Models\SamityDayChange;
use App\Models\SamityManagerChange;
use App\Models\SamityOfficerChange;
use App\Models\User;
use App\Models\Village;
use App\Models\WorkingArea;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Settings module: general+logo, branches, users, roles, holidays,
 *  notices, business day, geo masters, samities + lifecycle changes. */
class SettingsCrudTest extends CrudTestCase
{
    public function test_general_settings_save_with_logo_upload(): void
    {
        $profile = OrganizationProfile::active();

        // Exactly the General Settings form: profile[...] + settings{...}
        // + a logo file in one multipart POST.
        $this->post('/settings/general', [
            'profile' => [
                'name_en' => $profile->name_en, 'name_bn' => $profile->name_bn,
                'org_type' => $profile->org_type, 'registration_no' => $profile->registration_no,
                'mobile_app_name' => $profile->mobile_app_name, 'contact_person' => 'CRUD Contact',
                'address' => 'CRUD Address, Shahjadpur', 'phone' => '01711111111',
                'email' => 'crud@tpda.example',
            ],
            'settings' => ['app.name' => 'TPDA e-Sheba CRUD', 'gps.interval_seconds' => 120],
            'logo' => $this->realPng('org-logo.png'),
        ])->assertRedirect()->assertSessionHas('success');

        $fresh = OrganizationProfile::active();
        $this->assertSame('CRUD Contact', $fresh->contact_person);
        $this->assertSame('CRUD Address, Shahjadpur', $fresh->address);
        $this->assertNotEmpty($fresh->logo_path);
        $this->assertStringStartsWith('logos/', $fresh->logo_path);
        Storage::disk('private')->assertExists($fresh->logo_path);
        $this->assertSame('TPDA e-Sheba CRUD', app(SettingsService::class)->get('app.name'));
        $this->assertSame(120, app(SettingsService::class)->get('gps.interval_seconds'));

        // The uploaded logo is actually served back through the file route.
        $this->get('/files/'.$fresh->logo_path)->assertOk();

        // Read path: the settings page renders with the saved values.
        $this->get('/settings/general')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/General/Index')
                ->where('profile.contact_person', 'CRUD Contact'));
    }

    public function test_branches_create_read_update(): void
    {
        $head = $this->branch('101');

        $this->post('/settings/branches', [
            'code' => '103', 'name' => 'CRUD Branch', 'type' => 'Branch',
            'parent_branch_id' => $head->id, 'opening_date' => '2026-10-21',
            'country' => 'Bangladesh', 'division' => 'Rajshahi', 'district' => 'Sirajganj',
            'upazila' => 'Shahjadpur', 'union' => 'Talgachi',
            'division_id' => '5', 'district_id' => '62', 'upazila_id' => '670', 'union_id' => '6701',
            'manager_phone' => '01722222222', 'address' => 'CRUD branch address',
            'status' => 'Active',
        ])->assertRedirect()->assertSessionHas('success');

        $branch = Branch::where('code', '103')->firstOrFail();
        $this->assertSame('Sirajganj', $branch->district);
        $this->assertSame('62', $branch->district_id);

        $this->get('/settings/branches')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Branches/Index'));

        // The branches UI edits via a real PUT (no file payload).
        $this->put("/settings/branches/{$branch->id}", [
            'code' => '103', 'name' => 'CRUD Branch Renamed', 'type' => 'Branch',
            'parent_branch_id' => $head->id, 'opening_date' => '2026-10-21',
            'division' => 'Rajshahi', 'district' => 'Sirajganj',
            'division_id' => '5', 'district_id' => '62',
            'status' => 'Inactive',
        ])->assertRedirect()->assertSessionHas('success');

        $branch->refresh();
        $this->assertSame('CRUD Branch Renamed', $branch->name);
        $this->assertSame('Inactive', $branch->status);
        // NOTE: branches intentionally have no delete route/UI — removal
        // is by deactivation (status), asserted designed behaviour.
    }

    public function test_users_crud_role_assignment_and_lifecycle_actions(): void
    {
        $roleA = Role::where('name', '!=', 'super-admin')->orderBy('id')->firstOrFail();
        $roleB = Role::where('name', '!=', 'super-admin')->where('id', '!=', $roleA->id)->orderBy('id')->firstOrFail();
        $branch = $this->branch('102');
        $samity = $this->samity();

        $this->post('/settings/users', [
            'name' => 'CRUD User', 'username' => 'crud.user', 'email' => 'crud.user@tpda.example',
            'password' => 'Password@123', 'locale' => 'en',
            'roles' => [$roleA->name],
            'branch_id' => $branch->id,
            'samity_ids' => (string) $samity->id,
        ])->assertRedirect()->assertSessionHas('success');

        $user = User::where('username', 'crud.user')->firstOrFail();
        $this->assertTrue($user->hasRole($roleA->name));
        $this->assertTrue($user->branches->contains($branch->id));
        $this->assertTrue($user->must_change_password);

        $this->get('/settings/users')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Users/Index'));
        $this->get("/settings/users/{$user->id}/login-history")->assertOk();

        $this->put("/settings/users/{$user->id}", [
            'name' => 'CRUD User Renamed', 'username' => 'crud.user',
            'email' => 'crud.user@tpda.example', 'locale' => 'bn',
            'roles' => [$roleB->name],
            'branch_id' => $branch->id,
            'samity_ids' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('CRUD User Renamed', $user->name);
        $this->assertTrue($user->hasRole($roleB->name));
        $this->assertFalse($user->hasRole($roleA->name));

        // Designed end-state actions instead of delete: deactivate/reactivate.
        $this->post("/settings/users/{$user->id}/toggle-active")->assertSessionHas('success');
        $this->assertFalse($user->refresh()->is_active);
        $this->post("/settings/users/{$user->id}/toggle-active")->assertSessionHas('success');
        $this->assertTrue($user->refresh()->is_active);

        $this->post("/settings/users/{$user->id}/reset-password")
            ->assertSessionHas('success')->assertSessionHas('temp_password');
    }

    public function test_user_store_rejects_non_existent_employee_id(): void
    {
        // Regression: a hand-typed employee id used to pass validation and
        // crash the INSERT on the users.employee_id foreign key (500).
        $bogusId = (int) Employee::max('id') + 9999;

        $this->post('/settings/users', [
            'name' => 'Bogus Employee User', 'username' => 'bogus.employee', 'email' => 'bogus@tpda.example',
            'password' => 'Password@123', 'locale' => 'en', 'employee_id' => $bogusId,
            'roles' => [], 'samity_ids' => '',
        ])->assertRedirect()->assertSessionHasErrors('employee_id');

        $this->assertDatabaseMissing('users', ['username' => 'bogus.employee']);
    }

    public function test_user_store_links_real_employee_and_update_rejects_bogus_employee_id(): void
    {
        $employee = Employee::orderBy('id')->firstOrFail();

        // The index page must hand the form a real employees list to pick from.
        $this->get('/settings/users')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Users/Index')->has('employees'));

        $this->post('/settings/users', [
            'name' => 'Linked User', 'username' => 'linked.user', 'email' => 'linked@tpda.example',
            'password' => 'Password@123', 'locale' => 'en', 'employee_id' => $employee->id,
            'roles' => [], 'samity_ids' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $user = User::where('username', 'linked.user')->firstOrFail();
        $this->assertSame($employee->id, $user->employee_id);

        $this->put("/settings/users/{$user->id}", [
            'name' => 'Linked User', 'username' => 'linked.user',
            'email' => 'linked@tpda.example', 'locale' => 'en', 'employee_id' => (int) Employee::max('id') + 9999,
            'roles' => [], 'samity_ids' => '',
        ])->assertRedirect()->assertSessionHasErrors('employee_id');

        $this->assertSame($employee->id, $user->refresh()->employee_id);
    }

    public function test_user_branch_assignment_is_a_single_branch(): void
    {
        // Owner redesign (sync #71): the form's Branch dropdown is the
        // whole assignment — one user, one branch; none is allowed.
        $b102 = $this->branch('102');
        $b101 = $this->branch('101');

        $this->post('/settings/users', [
            'name' => 'One Branch User', 'username' => 'one.branch', 'email' => 'one.branch@tpda.example',
            'password' => 'Password@123', 'locale' => 'en',
            'roles' => [], 'branch_id' => $b102->id, 'samity_ids' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $user = User::where('username', 'one.branch')->firstOrFail();
        $this->assertSame([$b102->id], $user->branches->pluck('id')->all(), 'create stores exactly the selected branch');

        // Updating to another branch replaces the assignment outright.
        $this->put("/settings/users/{$user->id}", [
            'name' => 'One Branch User', 'username' => 'one.branch',
            'email' => 'one.branch@tpda.example', 'locale' => 'en',
            'roles' => [], 'branch_id' => $b101->id, 'samity_ids' => '',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame([$b101->id], $user->refresh()->branches->pluck('id')->all(), 'update replaces the branch');

        // No branch selected: allowed, stores no assignment.
        $this->post('/settings/users', [
            'name' => 'No Branch User', 'username' => 'no.branch', 'email' => 'no.branch@tpda.example',
            'password' => 'Password@123', 'locale' => 'en',
            'roles' => [], 'samity_ids' => '',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame([], User::where('username', 'no.branch')->firstOrFail()->branches->pluck('id')->all());

        // A branch id that does not exist is a validation error, not a 500.
        $this->post('/settings/users', [
            'name' => 'Bogus Branch User', 'username' => 'bogus.branch', 'email' => 'bogus.branch@tpda.example',
            'password' => 'Password@123', 'locale' => 'en',
            'roles' => [], 'branch_id' => (int) \App\Models\Branch::max('id') + 9999, 'samity_ids' => '',
        ])->assertRedirect()->assertSessionHasErrors('branch_id');
        $this->assertDatabaseMissing('users', ['username' => 'bogus.branch']);
    }

    public function test_roles_crud_and_permission_sync(): void
    {
        $perms = Permission::orderBy('name')->pluck('name')->take(3)->all();
        $this->assertCount(3, $perms);

        $this->post('/settings/roles', [
            'name' => 'crud-tester', 'label' => 'CRUD Tester', 'description' => 'Sweep role',
            'permissions' => $perms,
        ])->assertRedirect()->assertSessionHas('success');

        $role = Role::where('name', 'crud-tester')->firstOrFail();
        $this->assertEqualsCanonicalizing($perms, $role->permissions->pluck('name')->all());

        $this->get('/settings/roles')->assertOk();
        $this->get('/settings/permissions')->assertOk();

        $reduced = [$perms[0]];
        $this->put("/settings/roles/{$role->id}", [
            'name' => 'crud-tester', 'label' => 'CRUD Tester v2', 'description' => 'Sweep role',
            'permissions' => $reduced,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertEqualsCanonicalizing($reduced, $role->fresh()->permissions->pluck('name')->all());

        $this->delete("/settings/roles/{$role->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Role::find($role->id));

        // Designed guard: system roles refuse deletion with an error flash.
        $system = Role::where('name', 'super-admin')->firstOrFail();
        $this->delete("/settings/roles/{$system->id}")->assertSessionHas('error');
        $this->assertNotNull(Role::find($system->id));
    }

    public function test_holidays_create_single_and_range_then_delete(): void
    {
        $branch = $this->branch('102');

        $this->post('/settings/holidays', [
            'branch_id' => $branch->id, 'details' => 'CRUD Single Holiday',
            'holiday_date' => '2026-12-25',
        ])->assertRedirect()->assertSessionHas('success');
        $single = Holiday::where('details', 'CRUD Single Holiday')->firstOrFail();
        $this->assertSame('2026-12-25', $single->holiday_date->toDateString());

        $this->post('/settings/holidays', [
            'branch_id' => $branch->id, 'details' => 'CRUD Range Holiday',
            'date_from' => '2026-12-26', 'date_to' => '2026-12-27',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, Holiday::where('details', 'CRUD Range Holiday')->count());

        $this->get('/settings/holidays')->assertOk();

        $this->delete("/settings/holidays/{$single->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Holiday::find($single->id));
    }

    public function test_notices_create_and_delete(): void
    {
        $this->post('/settings/notices', [
            'text' => 'CRUD notice — office closed on Friday', 'text_color' => 'Green',
        ])->assertRedirect()->assertSessionHas('success');

        $notice = Notice::where('text', 'like', 'CRUD notice%')->firstOrFail();
        $this->get('/settings/notices')->assertOk();

        $this->delete("/settings/notices/{$notice->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Notice::find($notice->id));
    }

    public function test_business_day_page_close_and_auto_close_toggle(): void
    {
        $this->get('/settings/business-day')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/BusinessDay/Index'));

        $head = $this->branch('101');
        $before = BusinessDay::where('branch_id', $head->id)->where('is_closed', false)
            ->orderByDesc('business_date')->firstOrFail();

        // The toggle persists through SettingsService (row created on use).
        $this->post('/settings/business-day/auto-close', ['enabled' => true])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertTrue((bool) app(SettingsService::class)->get('business_day.auto_close', false));
        $this->post('/settings/business-day/auto-close', ['enabled' => false])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse((bool) app(SettingsService::class)->get('business_day.auto_close', false));

        $this->post('/settings/business-day/close')->assertRedirect()->assertSessionHas('success');
        // Closing marks the old row closed and opens a fresh row dated +1.
        $this->assertTrue($before->refresh()->is_closed);
        $after = BusinessDay::where('branch_id', $head->id)->where('is_closed', false)
            ->orderByDesc('business_date')->firstOrFail();
        $this->assertSame(
            $before->business_date->copy()->addDay()->toDateString(),
            $after->business_date->toDateString()
        );
    }

    public function test_villages_and_working_areas_crud_with_bd_geo_chain(): void
    {
        // bd-geo-info package values as the browser cascade submits them:
        // Rajshahi division → Sirajganj district → Shahjadpur upazila →
        // Talgachi union (plain id + name strings, no geo FKs).
        $chain = [
            'division_id' => '5', 'division_name' => 'Rajshahi',
            'district_id' => '62', 'district_name' => 'Sirajganj',
            'upazila_id' => '670', 'upazila_name' => 'Shahjadpur',
            'union_id' => '6701', 'union_name' => 'Talgachi',
        ];

        $this->post('/settings/geo/villages', [...$chain, 'name' => 'CRUD Village'])
            ->assertRedirect()->assertSessionHas('success');
        $village = Village::where('name', 'CRUD Village')->firstOrFail();
        $this->assertSame('Talgachi', $village->union_name);
        $this->assertSame('6701', $village->union_id);

        $this->get('/settings/geo/villages')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Geo/Index'));

        $this->put("/settings/geo/villages/{$village->id}", [...$chain, 'name' => 'CRUD Village Renamed'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('CRUD Village Renamed', $village->refresh()->name);

        $this->post('/settings/geo/working-areas', [
            ...$chain, 'name' => 'CRUD Working Area', 'village_id' => $village->id,
        ])->assertRedirect()->assertSessionHas('success');
        $area = WorkingArea::where('name', 'CRUD Working Area')->firstOrFail();
        $this->assertSame($village->id, $area->village_id);

        $this->get('/settings/geo/working-areas')->assertOk();

        $this->put("/settings/geo/working-areas/{$area->id}", [
            ...$chain, 'name' => 'CRUD Working Area Renamed', 'village_id' => $village->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('CRUD Working Area Renamed', $area->refresh()->name);

        $this->delete("/settings/geo/working-areas/{$area->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(WorkingArea::find($area->id));

        // Deleting the village unlinks (nullOnDelete) instead of erroring.
        $this->post('/settings/geo/working-areas', [
            ...$chain, 'name' => 'CRUD Orphan Area', 'village_id' => $village->id,
        ])->assertSessionHas('success');
        $orphan = WorkingArea::where('name', 'CRUD Orphan Area')->firstOrFail();
        $this->delete("/settings/geo/villages/{$village->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Village::find($village->id));
        $this->assertNull($orphan->refresh()->village_id);
    }

    public function test_samities_create_update_and_lifecycle_changes(): void
    {
        $branch = $this->branch('102');
        $officers = Employee::where('status', 'Active')->orderBy('id')->take(2)->get();
        $this->assertCount(2, $officers);
        $area = WorkingArea::orderBy('id')->first();

        $this->post('/settings/samities', [
            'branch_id' => $branch->id, 'name' => 'CRUD Samity',
            'open_date' => '2026-10-21', 'center_type' => 'Female',
            'meeting_frequency' => 'weekly', 'samity_day' => 'Wednesday',
            'working_area_id' => $area?->id,
            'field_officer_id' => $officers[0]->id, 'manager_id' => $officers[0]->id,
            'samity_phone' => '01733333333', 'start_time' => '09:30',
        ])->assertRedirect()->assertSessionHas('success');

        $samity = Samity::where('name', 'CRUD Samity')->firstOrFail();
        $this->assertNotEmpty($samity->samity_code);

        $this->get('/settings/samities')->assertOk();

        $this->put("/settings/samities/{$samity->id}", [
            'branch_id' => $branch->id, 'name' => 'CRUD Samity Renamed',
            'open_date' => '2026-10-21', 'center_type' => 'Female',
            'meeting_frequency' => 'weekly', 'samity_day' => 'Wednesday',
            'field_officer_id' => $officers[0]->id, 'manager_id' => $officers[0]->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('CRUD Samity Renamed', $samity->refresh()->name);

        // Day change (form posts the schedule: frequency + day or date).
        $this->post('/settings/samities/changes/day', [
            'samity_id' => $samity->id, 'meeting_frequency' => 'weekly', 'samity_day' => 'Friday',
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-22',
            'comments' => 'CRUD day change',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Friday', $samity->refresh()->samity_day);
        $this->assertNull($samity->date_of_month);
        $change = SamityDayChange::where('samity_id', $samity->id)->firstOrFail();
        $this->assertSame('Wednesday', $change->previous_value);
        $this->assertSame('Friday', $change->new_value);

        // Officer change.
        $this->post('/settings/samities/changes/officer', [
            'samity_id' => $samity->id, 'new_value' => $officers[1]->id,
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-22',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame($officers[1]->id, $samity->refresh()->field_officer_id);
        $this->assertSame(1, SamityOfficerChange::where('samity_id', $samity->id)->count());

        // Manager change.
        $this->post('/settings/samities/changes/manager', [
            'samity_id' => $samity->id, 'new_value' => $officers[1]->id,
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-22',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame($officers[1]->id, $samity->refresh()->manager_id);
        $this->assertSame(1, SamityManagerChange::where('samity_id', $samity->id)->count());

        $this->get('/settings/samities/changes/day')->assertOk();
        $this->get('/settings/samities/changes/officer')->assertOk();
        $this->get('/settings/samities/changes/manager')->assertOk();
    }
}
