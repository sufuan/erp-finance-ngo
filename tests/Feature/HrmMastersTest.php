<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Plan Step 5 acceptance: shift with all time fields; validation; gating. */
class HrmMastersTest extends TestCase
{
    use DatabaseTransactions;

    private function hrOfficer(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('hr-officer');
        $this->actingAs($user);

        return $user;
    }

    public function test_masters_are_seeded(): void
    {
        $this->assertSame(7, Department::count());
        $this->assertSame(7, \App\Models\Designation::count());
        $this->assertSame(4, \App\Models\EmployeeType::count());
        $shift = Shift::where('name', 'General Shift')->first();
        $this->assertSame('09:00:00', $shift->start_time);
        $this->assertSame(10, $shift->grace_minutes);
    }

    public function test_shift_can_be_created_with_all_time_fields(): void
    {
        $this->hrOfficer();
        $this->post('/hrm/shifts', [
            'name' => 'Field Shift', 'start_time' => '08:30', 'end_time' => '16:30', 'grace_minutes' => 15,
            'refresh_start' => '12:30', 'refresh_end' => '13:15',
            'overtime_start' => '16:30', 'overtime_end' => '19:00',
            'monthly_duty_days' => 24, 'daily_duty_minutes' => 480, 'allowed_late_days' => 2,
            'default_bonus_percent' => 5, 'default_deduction_percent' => 2.5, 'is_active' => true,
        ])->assertSessionHas('success');

        $shift = Shift::where('name', 'Field Shift')->first();
        $this->assertNotNull($shift);
        $this->assertSame('08:30:00', $shift->start_time);
        $this->assertSame('19:00:00', $shift->overtime_end);
        $this->assertSame('5.00', (string) $shift->default_bonus_percent);
    }

    public function test_shift_validation_requires_name_start_end_and_grace(): void
    {
        $this->hrOfficer();
        $this->post('/hrm/shifts', ['name' => 'Broken'])
            ->assertSessionHasErrors(['start_time', 'end_time', 'grace_minutes']);
        $this->assertNull(Shift::where('name', 'Broken')->first());
    }

    public function test_department_create_and_pages_render(): void
    {
        $this->hrOfficer();
        $branch = \App\Models\Branch::where('code', '102')->first();
        $this->post('/hrm/departments', [
            'branch_id' => $branch->id, 'name' => 'IT Cell', 'location' => 'Talgachi', 'incharge_id' => 42, 'is_active' => true,
        ])->assertSessionHas('success');
        $dept = Department::where('name', 'IT Cell')->first();
        $this->assertSame(42, $dept->incharge_id); // plain column until Step 6

        $this->get('/hrm/departments')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Hrm/Masters/Index')->where('entity', 'departments'));
        $this->get('/hrm/shifts')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Hrm/Masters/Index')->has('fields', 14));
    }

    public function test_hrm_pages_require_hrm_masters_permission(): void
    {
        $officer = User::factory()->create(['must_change_password' => false]);
        $officer->assignRole('field-officer');
        $this->actingAs($officer)->get('/hrm/departments')->assertForbidden();
    }
}
