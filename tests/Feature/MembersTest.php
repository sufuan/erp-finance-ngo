<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberUser;
use App\Models\Samity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Step 8 — members, portal user, KYC rules, transfer, closing. */
class MembersTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Same pattern as SamityTest: the seeded admin still owes a first-login
        // password change, so use a fresh super-admin with the flag cleared.
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    private function samity(string $name): Samity
    {
        return Samity::where('name', $name)->firstOrFail();
    }

    public function test_admission_creates_member_nominee_and_portal_user(): void
    {
        $samity = $this->samity('Talgachi Samity');

        $response = $this->actingAs($this->admin)->post('/members', [
            'samity_id' => $samity->id,
            'name' => 'New Member One',
            'gender' => 'Female',
            'mobile' => '01799998888',
            'national_id' => '5555123456',
            'share_amount' => 750,
            'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee One',
            'nominee_dob' => '1990-01-01',
            'nominee_relation' => 'Brother',
        ]);
        // Store returns to the admission page, where the success modal shows.
        $response->assertRedirect('/members/admission');

        $member = Member::where('mobile', '01799998888')->firstOrFail();
        $this->assertNotSame('', $member->member_no);
        $this->assertEquals($samity->branch_id, $member->branch_id);
        $this->assertSame('Nominee One', $member->nominees()->first()?->name);
        // Age is derived from dob, never stored as a column.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('nominees', 'age'));
        $this->assertSame(36, $member->nominees()->first()?->age);

        $portal = MemberUser::where('member_id', $member->id)->firstOrFail();
        $this->assertSame('01799998888', $portal->username);
        $this->assertFalse($portal->is_active);
        $this->assertTrue($portal->must_change_password);
    }

    public function test_admission_with_photo_stores_file(): void
    {
        $samity = $this->samity('Talgachi Samity');
        $this->actingAs($this->admin)->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Photo Member', 'gender' => 'Male',
            'mobile' => '01799997777', 'share_amount' => 100, 'open_date' => '2026-10-21',
            'profile_photo_file' => UploadedFile::fake()->image('p.jpg', 120, 120),
        ])->assertRedirect('/members/admission');
        $member = Member::where('mobile', '01799997777')->firstOrFail();
        $this->assertNotNull($member->profile_photo);
    }

    public function test_duplicate_nid_rejected_across_members_and_employees(): void
    {
        $samity = $this->samity('Talgachi Samity');
        $seededNid = Member::query()->whereNotNull('national_id')->value('national_id');
        $this->actingAs($this->admin)->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Dup Nid', 'gender' => 'Male',
            'mobile' => '01799996666', 'national_id' => $seededNid,
            'share_amount' => 100, 'open_date' => '2026-10-21',
        ])->assertSessionHasErrors('national_id');

        // Step 6 seed: the Executive Director's NID must also be unique.
        $employeeNid = \App\Models\Employee::query()->value('national_id');
        $this->actingAs($this->admin)->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Dup Emp Nid', 'gender' => 'Male',
            'mobile' => '01799995555', 'national_id' => $employeeNid,
            'share_amount' => 100, 'open_date' => '2026-10-21',
        ])->assertSessionHasErrors('national_id');
    }

    public function test_member_list_and_show_render(): void
    {
        $member = Member::query()->firstOrFail();
        $this->actingAs($this->admin)->get('/members')->assertOk();
        $this->actingAs($this->admin)->get('/members?samity_id='.$member->samity_id)->assertOk();
        $this->actingAs($this->admin)->get('/members/'.$member->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Members/Show'));
        $this->actingAs($this->admin)->get('/members/'.$member->id.'/admission-form')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Members/AdmissionForm'));
    }

    public function test_document_upload_and_visit_and_checkin(): void
    {
        $member = Member::query()->firstOrFail();
        $this->actingAs($this->admin)->post('/members/'.$member->id.'/documents', [
            'doc_type' => 'NID',
            'document' => UploadedFile::fake()->create('nid.pdf', 40, 'application/pdf'),
        ])->assertRedirect();
        $this->assertDatabaseHas('member_documents', ['member_id' => $member->id, 'doc_type' => 'NID']);

        $this->actingAs($this->admin)->post('/members/'.$member->id.'/visits', [
            'visit_date' => '2026-10-20', 'info' => 'Home visit', 'latitude' => '24.2278910', 'longitude' => '89.5708110',
        ])->assertRedirect();
        $this->assertDatabaseHas('member_visits', ['member_id' => $member->id, 'info' => 'Home visit']);

        $this->actingAs($this->admin)->post('/members/'.$member->id.'/check-in', [
            'latitude' => '24.2278910', 'longitude' => '89.5708110',
        ])->assertRedirect();
        $this->assertDatabaseHas('member_visits', ['member_id' => $member->id, 'info' => 'Check in']);
    }

    public function test_transfer_moves_member_between_samities(): void
    {
        $member = Member::where('samity_id', $this->samity('Talgachi Samity')->id)->firstOrFail();
        $target = $this->samity('Talgachi Mohila Samity');

        $this->actingAs($this->admin)->from('/members/transfer')->post('/members/transfer', [
            'member_id' => $member->id, 'to_samity_id' => $target->id,
            'transfer_date' => '2026-10-21', 'reason' => 'Requested',
        ])->assertRedirect('/members/transfer');

        $this->assertSame($target->id, $member->fresh()->samity_id);
        $this->assertDatabaseHas('member_transfers', [
            'member_id' => $member->id, 'to_samity_id' => $target->id, 'reason' => 'Requested',
        ]);

        // Same-samity transfer is rejected.
        $this->actingAs($this->admin)->post('/members/transfer', [
            'member_id' => $member->id, 'to_samity_id' => $target->id, 'transfer_date' => '2026-10-21',
        ])->assertSessionHasErrors('to_samity_id');
    }

    public function test_closing_blocks_and_cancel_restores(): void
    {
        $member = Member::where('mobile', '01815550002')->firstOrFail();

        $this->actingAs($this->admin)->from('/members/closing')->post('/members/closing', [
            'member_id' => $member->id, 'closing_date' => '2026-10-21',
            'closing_reason' => 'Voluntary', 'share_disposition' => 'Refunded',
        ])->assertRedirect('/members/closing');

        $fresh = $member->fresh();
        $this->assertSame('Closed', $fresh->status);
        $this->assertDatabaseHas('member_closings', [
            'member_id' => $member->id, 'closing_reason' => 'Voluntary', 'share_disposition' => 'Refunded',
        ]);
        // Portal user deactivated at closing.
        $this->assertSame(0, MemberUser::where('member_id', $member->id)->value('is_active') + 0);
        $this->assertFalse(MemberUser::where('member_id', $member->id)->first()->is_active);

        // Closing an already-closed member is blocked.
        $this->actingAs($this->admin)->post('/members/closing', [
            'member_id' => $member->id, 'closing_date' => '2026-10-21',
            'closing_reason' => 'Other', 'share_disposition' => 'Forfeited',
        ])->assertSessionHasErrors('member_id');

        // Cancel closing restores Active.
        $closing = \App\Models\MemberClosing::where('member_id', $member->id)->firstOrFail();
        $this->actingAs($this->admin)->from('/members/closing')->post('/members/closing/'.$closing->id.'/cancel')->assertRedirect('/members/closing');
        $this->assertSame('Active', $member->fresh()->status);
        $this->assertNotNull($closing->fresh()->cancelled_at);
    }
}
