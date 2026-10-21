<?php

namespace Tests\Feature\Crud;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeType;
use App\Models\Shift;

/** HRM masters: departments, designations, employment types, shifts —
 *  full create → read → update → delete through /hrm/{entity}. */
class HrmCrudTest extends CrudTestCase
{
    public function test_departments_crud(): void
    {
        $branch = $this->branch('102');
        $incharge = Employee::where('status', 'Active')->orderBy('id')->firstOrFail();

        $this->post('/hrm/departments', [
            'branch_id' => $branch->id, 'name' => 'CRUD Department', 'location' => 'Head Office',
            'incharge_id' => $incharge->id, 'is_active' => true,
        ])->assertRedirect()->assertSessionHas('success');
        $dept = Department::where('name', 'CRUD Department')->firstOrFail();
        $this->assertSame($branch->id, $dept->branch_id);

        $this->get('/hrm/departments')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Hrm/Masters/Index'));

        $this->put("/hrm/departments/{$dept->id}", [
            'branch_id' => $branch->id, 'name' => 'CRUD Department Renamed', 'location' => 'Field',
            'incharge_id' => $incharge->id, 'is_active' => true,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('CRUD Department Renamed', $dept->refresh()->name);

        $this->delete("/hrm/departments/{$dept->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Department::find($dept->id));
    }

    public function test_designations_crud(): void
    {
        $this->post('/hrm/designations', ['name' => 'CRUD Designation', 'is_active' => true])
            ->assertRedirect()->assertSessionHas('success');
        $row = Designation::where('name', 'CRUD Designation')->firstOrFail();

        $this->get('/hrm/designations')->assertOk();
        $this->put("/hrm/designations/{$row->id}", ['name' => 'CRUD Designation Renamed', 'is_active' => false])
            ->assertRedirect()->assertSessionHas('success');
        $row->refresh();
        $this->assertSame('CRUD Designation Renamed', $row->name);
        $this->assertFalse((bool) $row->is_active);

        $this->delete("/hrm/designations/{$row->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Designation::find($row->id));
    }

    public function test_employee_types_crud(): void
    {
        $this->post('/hrm/employee-types', ['name' => 'CRUD Type', 'is_active' => true])
            ->assertRedirect()->assertSessionHas('success');
        $row = EmployeeType::where('name', 'CRUD Type')->firstOrFail();

        $this->get('/hrm/employee-types')->assertOk();
        $this->put("/hrm/employee-types/{$row->id}", ['name' => 'CRUD Type Renamed', 'is_active' => true])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('CRUD Type Renamed', $row->refresh()->name);

        $this->delete("/hrm/employee-types/{$row->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(EmployeeType::find($row->id));
    }

    public function test_shifts_crud(): void
    {
        $payload = [
            'name' => 'CRUD Shift', 'start_time' => '09:00', 'end_time' => '17:00',
            'grace_minutes' => 10, 'refresh_start' => '13:00', 'refresh_end' => '14:00',
            'overtime_start' => '17:00', 'overtime_end' => '19:00',
            'monthly_duty_days' => 26, 'daily_duty_minutes' => 480, 'allowed_late_days' => 3,
            'default_bonus_percent' => 5, 'default_deduction_percent' => 2, 'is_active' => true,
        ];
        $this->post('/hrm/shifts', $payload)->assertRedirect()->assertSessionHas('success');
        $shift = Shift::where('name', 'CRUD Shift')->firstOrFail();
        $this->assertSame('09:00', substr((string) $shift->start_time, 0, 5));

        $this->get('/hrm/shifts')->assertOk();
        $payload['name'] = 'CRUD Shift Renamed';
        $payload['grace_minutes'] = 15;
        $this->put("/hrm/shifts/{$shift->id}", $payload)
            ->assertRedirect()->assertSessionHas('success');
        $shift->refresh();
        $this->assertSame('CRUD Shift Renamed', $shift->name);
        $this->assertSame(15, (int) $shift->grace_minutes);

        $this->delete("/hrm/shifts/{$shift->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Shift::find($shift->id));
    }
}
