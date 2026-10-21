<?php

namespace Tests\Feature\Crud;

use App\Models\Branch;
use App\Models\Director;
use App\Models\Employee;
use Illuminate\Support\Facades\Storage;

/**
 * Registers: employees + directors through the real register routes,
 * with the FULL form payload (personal + bank sections) and real photo
 * uploads — including the two flows the owner personally broke:
 * employee status ''→null and the director photo route-param TypeError.
 *
 * Registers intentionally expose no delete route or UI action; the
 * designed end-state is status → Inactive, asserted below.
 */
class RegisterCrudTest extends CrudTestCase
{
    /** Mirrors the register React form (personal + bank sections). */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => Branch::where('code', '102')->firstOrFail()->id,
            'name' => 'CRUD Employee',
            'father_name' => 'Father CRUD',
            'mother_name' => 'Mother CRUD',
            'gender' => 'Male',
            'present_address' => 'Present address CRUD',
            'permanent_address' => 'Permanent address CRUD',
            'dob' => '1992-02-12',
            'national_id' => '1992123456',
            'religion' => 'Islam',
            'blood_group' => 'AB-',
            'education' => 'BSc',
            'marital_status' => 'Married',
            'mobile' => '01714444444',
            'emergency_mobile' => '01715555556',
            'email' => 'crud.employee@tpda.example',
            'joining_date' => '2024-01-15',
            'bank_name' => 'Sonali Bank',
            'bank_account_number' => '12345678901',
            'bank_account_name' => 'CRUD Employee',
            'bank_branch_name' => 'Shahjadpur',
            'branch_routing_number' => '200812345',
            'bank_comments' => 'CRUD payroll account',
            'status' => 'Active',
        ], $overrides);
    }

    private function directorPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'CRUD Director',
            'father_name' => 'Father CRUD',
            'mother_name' => 'Mother CRUD',
            'gender' => 'Female',
            'present_address' => 'Director present address',
            'permanent_address' => 'Director permanent address',
            'dob' => '1980-05-10',
            'national_id' => '1980123456',
            'religion' => 'Islam',
            'blood_group' => 'O+',
            'education' => 'MBA',
            'marital_status' => 'Married',
            'mobile' => '01716666667',
            'email' => 'crud.director@tpda.example',
            'bank_name' => 'Janata Bank',
            'bank_account_number' => '98765432109',
            'bank_account_name' => 'CRUD Director',
            'bank_branch_name' => 'Ullapara',
            'branch_routing_number' => '200812346',
            'director_status' => 'Active',
        ], $overrides);
    }

    public function test_employee_create_with_photo_read_and_edit_with_new_photo(): void
    {
        // Owner regression: an untouched status select submits '' — the
        // create must succeed and land on Active, never a null 500.
        $this->post('/settings/employees', $this->employeePayload([
            'status' => '', 'photo' => $this->fakeImage('employee.jpg'),
        ]))->assertRedirect()->assertSessionHas('success');

        $employee = Employee::where('mobile', '01714444444')->firstOrFail();
        $this->assertSame('Active', $employee->status);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $employee->employee_code);
        $this->assertNotNull($employee->photo_path);
        $this->assertStringStartsWith('employees/', $employee->photo_path);
        Storage::disk('private')->assertExists($employee->photo_path);
        $this->get('/files/'.$employee->photo_path)->assertOk();
        $firstPhoto = $employee->photo_path;

        // Read: register list renders and finds the new row by search.
        $this->get('/settings/employees?search=CRUD+Employee')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Registers/Index')->has('items.data', 1));

        // Edit exactly like the browser: multipart POST + _method=PUT,
        // with a replacement photo.
        $this->post("/settings/employees/{$employee->id}", [
            '_method' => 'PUT',
            'photo' => $this->fakeImage('employee-new.jpg'),
        ] + $this->employeePayload(['name' => 'CRUD Employee Renamed', 'status' => 'Inactive']))
            ->assertRedirect()->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame('CRUD Employee Renamed', $employee->name);
        $this->assertSame('Inactive', $employee->status);
        $this->assertNotNull($employee->photo_path);
        $this->assertNotSame($firstPhoto, $employee->photo_path);
        Storage::disk('private')->assertExists($employee->photo_path);

        // No delete exists for registers by design — the route answers
        // 405 (method not allowed), not a 500, and the row survives.
        $this->delete("/settings/employees/{$employee->id}")->assertStatus(405);
        $this->assertNotNull(Employee::find($employee->id));
    }

    public function test_director_create_with_photo_and_edit_with_photo(): void
    {
        $this->post('/settings/directors', $this->directorPayload([
            'photo' => $this->fakeImage('director.jpg'),
        ]))->assertRedirect()->assertSessionHas('success');

        $director = Director::where('mobile', '01716666667')->firstOrFail();
        $this->assertSame('Active', $director->director_status);
        $this->assertNotNull($director->photo_path);
        $this->assertStringStartsWith('directors/', $director->photo_path);
        Storage::disk('private')->assertExists($director->photo_path);

        $this->get('/settings/directors?search=CRUD+Director')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Registers/Index')->has('items.data', 1));

        // Owner regression: this spoofed-PUT photo upload used to throw
        // "RegisterController::update(): Argument #3 ($id) must be of
        // type int, string given".
        $firstPhoto = $director->photo_path;
        $this->post("/settings/directors/{$director->id}", [
            '_method' => 'PUT',
            'photo' => $this->fakeImage('director-new.jpg'),
        ] + $this->directorPayload(['name' => 'CRUD Director Renamed', 'director_status' => 'Inactive']))
            ->assertRedirect()->assertSessionHas('success');

        $director->refresh();
        $this->assertSame('CRUD Director Renamed', $director->name);
        $this->assertSame('Inactive', $director->director_status);
        $this->assertNotSame($firstPhoto, $director->photo_path);
        Storage::disk('private')->assertExists($director->photo_path);
        $this->get('/files/'.$director->photo_path)->assertOk();

        $this->delete("/settings/directors/{$director->id}")->assertStatus(405);
        $this->assertNotNull(Director::find($director->id));
    }
}
