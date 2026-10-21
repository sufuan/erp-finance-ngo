<?php

namespace Tests\Feature\Crud;

use App\Models\Member;
use App\Models\MemberClosing;
use App\Models\MemberDocument;
use App\Models\MemberTransfer;
use App\Models\MemberVisit;
use Illuminate\Support\Facades\Storage;

/**
 * Members: admission with photo uploads, documents (incl. signature),
 * visits/check-in, edit via the browser's spoofed PUT, samity transfer,
 * and the closing → cancel-closing lifecycle.
 */
class MemberCrudTest extends CrudTestCase
{
    /** Mirrors the Admission.jsx form state (emptyForm + files). */
    private function admissionPayload(array $overrides = []): array
    {
        return array_merge([
            'samity_id' => $this->samity()->id,
            'name' => 'CRUD Member', 'father_name' => 'Father CRUD', 'mother_name' => 'Mother CRUD',
            'spouse_name' => 'Spouse CRUD', 'gender' => 'Female', 'religion' => 'Islam',
            'blood_group' => 'B+', 'marital_status' => 'Married', 'birth_date' => '1996-03-14',
            'mobile' => '01717777778', 'emergency_phone' => '01718888889',
            'email' => 'crud.member@tpda.example', 'national_id' => '1996123456',
            'passport_no' => '', 'tin' => '', 'occupation' => 'Farmer', 'education' => 'HSC',
            'present_address' => 'Talgachi, Shahjadpur', 'permanent_address' => 'Talgachi, Shahjadpur',
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'CRUD Nominee', 'nominee_dob' => '1998-07-01',
            'nominee_address' => 'Talgachi, Shahjadpur', 'nominee_phone' => '01719999990',
            'nominee_relation' => 'Wife',
        ], $overrides);
    }

    private function admit(array $overrides = []): Member
    {
        $this->post('/members', $this->admissionPayload($overrides))
            ->assertRedirect('/members/admission')
            ->assertSessionHas('success')
            ->assertSessionHas('created_member_no');

        return Member::where('mobile', $overrides['mobile'] ?? '01717777778')->firstOrFail();
    }

    public function test_admission_with_photos_documents_and_reads(): void
    {
        $member = $this->admit([
            'profile_photo_file' => $this->fakeImage('member.jpg'),
            'nominee_photo_file' => $this->fakeImage('nominee.jpg'),
        ]);

        $this->assertNotEmpty($member->member_no);
        $this->assertSame('Active', $member->status);
        $this->assertNotNull($member->profile_photo);
        $this->assertStringStartsWith('members/', $member->profile_photo);
        Storage::disk('private')->assertExists($member->profile_photo);
        $this->get('/files/'.$member->profile_photo)->assertOk();

        $nominee = $member->nominees()->firstOrFail();
        $this->assertSame('CRUD Nominee', $nominee->name);
        $this->assertNotNull($nominee->photo);
        $this->assertStringStartsWith('nominees/', $nominee->photo);
        Storage::disk('private')->assertExists($nominee->photo);

        // Reads: list (with a filter), 360 show page, printable form.
        $this->get('/members?search=CRUD+Member')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Members/Index')->has('members.data'));
        $this->get("/members/{$member->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Members/Show'));
        $this->get("/members/{$member->id}/admission-form")->assertOk();

        // Signature upload — a document of type Signature, like the UI.
        $this->post("/members/{$member->id}/documents", [
            'doc_type' => 'Signature', 'document' => $this->fakeImage('signature.png'),
        ])->assertRedirect()->assertSessionHas('success');
        $signature = MemberDocument::where('member_id', $member->id)->where('doc_type', 'Signature')->firstOrFail();
        $this->assertStringStartsWith('member-documents/', $signature->file_path);
        Storage::disk('private')->assertExists($signature->file_path);
        $this->get('/files/'.$signature->file_path)->assertOk();

        // NID document upload (PDF through the 'document' upload type).
        $this->post("/members/{$member->id}/documents", [
            'doc_type' => 'NID',
            'document' => \Illuminate\Http\UploadedFile::fake()->create('nid.pdf', 64, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, MemberDocument::where('member_id', $member->id)->count());

        // Field visit + GPS check-in + location update.
        $this->post("/members/{$member->id}/visits", [
            'visit_date' => '2026-10-21', 'info' => 'CRUD home visit',
            'latitude' => 24.1763, 'longitude' => 89.5889,
        ])->assertRedirect()->assertSessionHas('success');
        $this->post("/members/{$member->id}/check-in", [
            'latitude' => 24.1763, 'longitude' => 89.5889,
        ])->assertRedirect()->assertSessionHas('success');
        $this->post("/members/{$member->id}/location", [
            'latitude' => 24.1800, 'longitude' => 89.5900,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(3, MemberVisit::where('member_id', $member->id)->count());
    }

    public function test_member_edit_via_spoofed_put_with_replacement_photo(): void
    {
        $member = $this->admit(['profile_photo_file' => $this->fakeImage('before.jpg')]);
        $firstPhoto = $member->profile_photo;

        $this->get("/members/{$member->id}/edit")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Members/Admission'));

        // The edit form posts multipart with _method=put (Admission.jsx).
        $this->post("/members/{$member->id}", ['_method' => 'put'] + $this->admissionPayload([
            'name' => 'CRUD Member Renamed', 'occupation' => 'Trader',
            'profile_photo_file' => $this->fakeImage('after.jpg'),
        ]))->assertRedirect("/members/{$member->id}")->assertSessionHas('success');

        $member->refresh();
        $this->assertSame('CRUD Member Renamed', $member->name);
        $this->assertSame('Trader', $member->occupation);
        $this->assertNotSame($firstPhoto, $member->profile_photo);
        Storage::disk('private')->assertExists($member->profile_photo);
    }

    public function test_member_transfer_then_closing_and_cancel_closing(): void
    {
        $member = $this->admit(['mobile' => '01710000001', 'national_id' => '1996111111']);
        $target = $this->samity('Talgachi Mohila Samity');
        $this->assertSame($member->branch_id, $target->branch_id);

        $this->get('/members/transfer')->assertOk();
        $this->post('/members/transfer', [
            'member_id' => $member->id, 'to_samity_id' => $target->id,
            'transfer_date' => '2026-10-21', 'reason' => 'CRUD relocation',
        ])->assertRedirect()->assertSessionHas('success');

        $member->refresh();
        $this->assertSame($target->id, $member->samity_id);
        $transfer = MemberTransfer::where('member_id', $member->id)->firstOrFail();
        $this->assertSame($target->id, $transfer->to_samity_id);

        // Closing flow: member has no loans/deposit balance, so it closes.
        $this->get('/members/closing')->assertOk();
        $this->post('/members/closing', [
            'member_id' => $member->id, 'closing_date' => '2026-10-21',
            'closing_reason' => 'Voluntary', 'share_disposition' => 'Refunded',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Closed', $member->refresh()->status);
        $closing = MemberClosing::where('member_id', $member->id)->firstOrFail();
        $this->assertSame('500.00', (string) $closing->share_amount_at_closing);

        // Designed reversal: cancelling the closing reactivates the member.
        $this->post("/members/closing/{$closing->id}/cancel")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Active', $member->refresh()->status);
        $this->assertNotNull($closing->refresh()->cancelled_at);
    }
}
