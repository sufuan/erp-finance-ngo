<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Director;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Plan Step 6 acceptance: registers, codes, duplicates, FKs, gating. */
class EmployeeRegisterTest extends TestCase
{
    use DatabaseTransactions;

    private function hrOfficer(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('hr-officer');
        $this->actingAs($user);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        $branch = Branch::where('code', '102')->first();

        return array_merge([
            'branch_id' => $branch->id,
            'name' => 'Test Employee',
            'father_name' => 'Father X',
            'mother_name' => 'Mother Y',
            'gender' => 'Male',
            'joining_date' => '2024-01-15',
            'dob' => '1995-04-02',
            'national_id' => '1995123456',
            'mobile' => '01715555555',
            'bank_name' => 'Sonali Bank',
            'bank_account_number' => '12345678901',
            'bank_account_name' => 'Test Employee',
            'bank_branch_name' => 'Shahjadpur',
            'branch_routing_number' => '200812345',
        ], $overrides);
    }

    public function test_seeded_employees_have_six_digit_codes(): void
    {
        $this->assertSame(4, Employee::count());
        $mostafa = Employee::where('name', 'Md. Mostafa')->first();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $mostafa->employee_code);
        // NID stored encrypted, hash matches plaintext lookup.
        $raw = DB::table('employees')->where('id', $mostafa->id)->value('national_id');
        $this->assertNotSame('1975123456', $raw);
        $this->assertSame(hash('sha256', '1975123456'), $mostafa->nid_hash);
        $this->assertSame('1975123456', $mostafa->national_id);
    }

    public function test_branch_manager_and_department_incharge_fks_work(): void
    {
        $talgachi = Branch::where('code', '102')->first();
        $this->assertNotNull($talgachi->manager_id);
        $this->assertSame('Md. Abdul Karim', Employee::find($talgachi->manager_id)->name);
        $dept = Department::where('branch_id', $talgachi->id)->where('name', 'Microfinance Operations')->first();
        $this->assertSame($talgachi->manager_id, $dept->incharge_id);
    }

    public function test_register_employee_with_photo_persists_all_fields(): void
    {
        $this->hrOfficer();
        $photo = UploadedFile::fake()->image('photo.jpg', 200, 200);
        $this->post('/settings/employees', $this->payload(['photo' => $photo]))
            ->assertSessionHas('success');

        $employee = Employee::where('mobile', '01715555555')->first();
        $this->assertNotNull($employee);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $employee->employee_code);
        $this->assertSame('Sonali Bank', $employee->bank_name);
        $this->assertSame('200812345', $employee->branch_routing_number);
        $this->assertSame('1995123456', $employee->national_id);
        $this->assertNotNull($employee->photo_path);
    }

    public function test_duplicate_nid_rejected_across_registers(): void
    {
        $this->hrOfficer();
        $this->post('/settings/employees', $this->payload())
            ->assertSessionHas('success');
        // Same NID in the director register must fail.
        $this->post('/settings/directors', [
            'name' => 'Dup Director', 'gender' => 'Female',
            'national_id' => '1995123456', 'mobile' => '01816666666',
        ])->assertSessionHasErrors(['national_id']);
        $this->assertNull(Director::where('mobile', '01816666666')->first());
    }

    public function test_duplicate_mobile_rejected(): void
    {
        $this->hrOfficer();
        $this->post('/settings/employees', $this->payload())->assertSessionHas('success');
        $this->post('/settings/employees', $this->payload(['national_id' => '1995987654']))
            ->assertSessionHasErrors(['mobile']);
    }

    public function test_invalid_nid_and_mobile_formats_rejected(): void
    {
        $this->hrOfficer();
        $this->post('/settings/employees', $this->payload(['national_id' => '12345']))
            ->assertSessionHasErrors(['national_id']);
        $this->post('/settings/employees', $this->payload(['mobile' => '01215555555']))
            ->assertSessionHasErrors(['mobile']);
    }

    public function test_director_register_crud(): void
    {
        $this->hrOfficer();
        $this->post('/settings/directors', [
            'name' => 'Board Director', 'gender' => 'Male', 'mobile' => '01717777777',
            'national_id' => '1960112233', 'director_status' => 'Active',
        ])->assertSessionHas('success');
        $director = Director::where('mobile', '01717777777')->first();
        $this->assertSame('Active', $director->director_status);
        $this->assertSame('1960112233', $director->national_id);
    }

    public function test_employee_create_without_status_defaults_to_active(): void
    {
        $this->hrOfficer();
        // No status key at all.
        $this->post('/settings/employees', $this->payload())->assertSessionHas('success');
        $this->assertSame('Active', Employee::where('mobile', '01715555555')->first()->status);

        // Empty status — exactly what the browser form submits when the
        // select is left untouched ('' → null must never reach the insert).
        $this->post('/settings/employees', $this->payload([
            'status' => '', 'mobile' => '01716666666', 'national_id' => '1995987654',
        ]))->assertSessionHas('success');
        $this->assertSame('Active', Employee::where('mobile', '01716666666')->first()->status);

        // Explicit Inactive is respected.
        $this->post('/settings/employees', $this->payload([
            'status' => 'Inactive', 'mobile' => '01717777777', 'national_id' => '1995111222',
        ]))->assertSessionHas('success');
        $this->assertSame('Inactive', Employee::where('mobile', '01717777777')->first()->status);
    }

    public function test_director_create_without_status_defaults_to_active(): void
    {
        $this->hrOfficer();
        $this->post('/settings/directors', [
            'name' => 'No Status Director', 'gender' => 'Male', 'mobile' => '01718888888',
            'national_id' => '1960998877', 'director_status' => '',
        ])->assertSessionHas('success');
        $this->assertSame('Active', Director::where('mobile', '01718888888')->first()->director_status);
    }

    public function test_director_update_with_photo_upload_succeeds(): void
    {
        $this->hrOfficer();
        $this->post('/settings/directors', [
            'name' => 'Photo Director', 'gender' => 'Female', 'mobile' => '01719999999',
            'national_id' => '1960554433', 'director_status' => 'Active',
        ])->assertSessionHas('success');
        $director = Director::where('mobile', '01719999999')->first();
        $this->assertNull($director->photo_path);

        // The browser edits via POST + _method=PUT with multipart data.
        $photo = UploadedFile::fake()->image('director.jpg', 200, 200);
        $this->post("/settings/directors/{$director->id}", [
            '_method' => 'PUT',
            'name' => 'Photo Director', 'gender' => 'Female', 'mobile' => '01719999999',
            'national_id' => '1960554433', 'director_status' => 'Active',
            'photo' => $photo,
        ])->assertSessionHas('success');

        $director->refresh();
        $this->assertNotNull($director->photo_path);
        $this->assertStringStartsWith('directors/', $director->photo_path);
    }

    public function test_employee_update_via_spoofed_put_succeeds(): void
    {
        $this->hrOfficer();
        $this->post('/settings/employees', $this->payload())->assertSessionHas('success');
        $employee = Employee::where('mobile', '01715555555')->first();

        $this->post("/settings/employees/{$employee->id}", [
            '_method' => 'PUT',
        ] + $this->payload(['name' => 'Renamed Employee', 'status' => 'Inactive']))
            ->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame('Renamed Employee', $employee->name);
        $this->assertSame('Inactive', $employee->status);
    }

    public function test_register_requires_hrm_employees_permission(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('field-officer');
        $this->actingAs($user);
        $this->get('/settings/employees')->assertForbidden();
    }

    public function test_employee_index_page_renders(): void
    {
        $this->hrOfficer();
        $this->get('/settings/employees')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Registers/Index')->has('items.data', 4));
    }
}
