<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Client round 2026-11-18 (Mostofa): member-form branch/samity fixes.
 *
 *  1. The admission form's Samity dropdown came up EMPTY: the options
 *     were filtered to the session's active branch (which defaults to
 *     Head Office — a branch with no samities) and the form had no
 *     Branch field at all. The form is now Name → Branch → Samity,
 *     with the picker list branch-scoped (SamityScope lifted for the
 *     picker only, exactly like the user-form samity picker).
 *  2. Branch comes before Samity in member screens.
 *  3. Share amount is OPTIONAL at member opening (empty → 0).
 *  4. Member search cascades Branch → Samity → member suggestions.
 *  5. Members are never hard-deleted in this system — the closing
 *     flow is the lifecycle end, exercised here as the CRUD "remove".
 */
class MemberBranchSamityCascadeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Branch $headOffice;

    private Branch $talgachi;

    private Samity $samityA;

    private Samity $samityB;

    private int $mobileSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        $this->headOffice = Branch::where('code', '101')->firstOrFail();
        $this->talgachi = Branch::where('code', '102')->firstOrFail();
        $this->samityA = Samity::withoutGlobalScopes()->where('samity_code', '0001')->firstOrFail();
        $this->samityB = Samity::withoutGlobalScopes()->where('samity_code', '0002')->firstOrFail();
    }

    private function mobile(): string
    {
        return '01766'.str_pad((string) (++$this->mobileSeq), 6, '0', STR_PAD_LEFT);
    }

    private function payload(Samity $samity, array $overrides = []): array
    {
        return [
            'samity_id' => $samity->id,
            'name' => 'Cascade Member',
            'gender' => 'Female',
            'mobile' => $this->mobile(),
            'open_date' => '2026-10-21',
            ...$overrides,
        ];
    }

    private function createMember(Samity $samity, array $overrides = []): Member
    {
        $payload = $this->payload($samity, $overrides);
        $this->actingAs($this->admin)->post('/members', $payload)->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $payload['mobile'])->firstOrFail();
    }

    // --- Point 1: the empty-dropdown bug --------------------------------

    public function test_admission_options_offer_branches_and_all_their_samities(): void
    {
        // The super admin's session active branch defaults to Head
        // Office (101), which has NO samities — the old options query
        // filtered to that branch and the dropdown rendered empty.
        $response = $this->actingAs($this->admin)->get('/members/admission');
        $response->assertOk()->assertInertia(fn ($p) => $p->component('Members/Admission'));

        $options = $response->viewData('page')['props']['options'];
        $branchIds = collect($options['branches'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->headOffice->id, $branchIds);
        $this->assertContains($this->talgachi->id, $branchIds);

        $samities = collect($options['samities']);
        $this->assertNotEmpty($samities, 'Samity picker must not be empty');
        $this->assertTrue($samities->contains(fn ($s) => (int) $s['id'] === $this->samityA->id && (int) $s['branch_id'] === $this->talgachi->id));
        $this->assertTrue($samities->contains(fn ($s) => (int) $s['id'] === $this->samityB->id && (int) $s['branch_id'] === $this->talgachi->id));
    }

    public function test_member_list_options_carry_the_same_cascade(): void
    {
        $response = $this->actingAs($this->admin)->get('/members');
        $response->assertOk();
        $options = $response->viewData('page')['props']['options'];
        $this->assertNotEmpty($options['branches']);
        $this->assertNotEmpty($options['samities']);
    }

    // --- Point 3: share amount optional ---------------------------------

    public function test_create_member_without_share_amount_succeeds_with_zero(): void
    {
        $payload = $this->payload($this->samityA, ['name' => 'No Share Member']);
        unset($payload['share_amount']); // key not sent at all

        $this->actingAs($this->admin)->post('/members', $payload)->assertRedirect('/members/admission');

        $member = Member::withoutGlobalScopes()->where('mobile', $payload['mobile'])->firstOrFail();
        $this->assertSame('No Share Member', $member->name);
        $this->assertEquals(0, (float) $member->share_amount);
        $this->assertSame($this->talgachi->id, $member->branch_id);
    }

    public function test_create_member_with_blank_share_amount_succeeds(): void
    {
        // The React form posts an empty string when the field is left blank.
        $member = $this->createMember($this->samityA, ['share_amount' => '']);
        $this->assertEquals(0, (float) $member->share_amount);
    }

    public function test_create_member_with_share_amount_keeps_it(): void
    {
        $member = $this->createMember($this->samityA, ['share_amount' => 500]);
        $this->assertEquals(500, (float) $member->share_amount);
    }

    // --- Point 1/4 for a restricted user ---------------------------------

    private function makeClerk(): User
    {
        $this->actingAs($this->admin)->post('/settings/roles', [
            'name' => 'member-clerk', 'label' => 'Member Clerk', 'description' => 'Admits members',
            'permissions' => ['members.view', 'members.create', 'members.edit', 'members.close'],
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($this->admin)->post('/settings/users', [
            'name' => 'Cascade Clerk', 'username' => 'cascade.clerk',
            'email' => 'cascade.clerk@tpda.example', 'password' => 'Password@123',
            'locale' => 'en', 'roles' => ['member-clerk'],
            'branch_id' => $this->talgachi->id, 'samity_ids' => [$this->samityA->id],
        ])->assertRedirect()->assertSessionHas('success');

        $user = User::where('username', 'cascade.clerk')->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    public function test_restricted_user_picker_is_populated_for_his_branch(): void
    {
        $clerk = $this->makeClerk();

        $response = $this->actingAs($clerk)->get('/members/admission');
        $response->assertOk();
        $options = $response->viewData('page')['props']['options'];

        $branchIds = collect($options['branches'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->talgachi->id, $branchIds, 'His assigned branch must be offered');
        $this->assertNotContains($this->headOffice->id, $branchIds, 'A branch he is not assigned to must not be offered');

        $samities = collect($options['samities']);
        $this->assertNotEmpty($samities, 'Picker must not come up empty for a user with assignments');
        $this->assertTrue($samities->every(fn ($s) => (int) $s['branch_id'] === $this->talgachi->id), 'Picker samities stay inside his branches');
        $this->assertTrue($samities->contains(fn ($s) => (int) $s['id'] === $this->samityA->id), 'His ticked samity must be offered');
    }

    public function test_restricted_user_can_create_member_in_his_samity_without_share(): void
    {
        $clerk = $this->makeClerk();
        $payload = $this->payload($this->samityA, ['name' => 'Clerk Member']);

        $this->actingAs($clerk)->post('/members', $payload)->assertRedirect('/members/admission');
        $member = Member::withoutGlobalScopes()->where('mobile', $payload['mobile'])->firstOrFail();
        $this->assertEquals(0, (float) $member->share_amount);
    }

    public function test_restricted_user_still_sees_only_ticked_samity_members_elsewhere(): void
    {
        $clerk = $this->makeClerk();
        $inA = $this->createMember($this->samityA, ['name' => 'Visible Member']);
        $inB = $this->createMember($this->samityB, ['name' => 'Hidden Member']);

        // Member list stays assignment-scoped (the picker lift must not leak).
        $response = $this->actingAs($clerk)->get('/members');
        $response->assertOk();
        $nos = collect($response->viewData('page')['props']['members']['data'])->pluck('member_no')->all();
        $this->assertContains($inA->member_no, $nos);
        $this->assertNotContains($inB->member_no, $nos);

        // Suggestions for the unticked samity come back empty for him.
        $response = $this->actingAs($clerk)->get('/members?samity_id='.$this->samityB->id);
        $response->assertOk();
        $this->assertSame([], $response->viewData('page')['props']['suggestions']);
    }

    // --- Point 4: search cascade suggestions ------------------------------

    public function test_search_cascade_suggests_only_the_chosen_samitys_members(): void
    {
        $inA = $this->createMember($this->samityA, ['name' => 'Alpha Member']);
        $inB = $this->createMember($this->samityB, ['name' => 'Beta Member']);

        $response = $this->actingAs($this->admin)->get('/members?branch_id='.$this->talgachi->id.'&samity_id='.$this->samityA->id);
        $response->assertOk();
        $suggestions = collect($response->viewData('page')['props']['suggestions']);
        $nos = $suggestions->pluck('member_no')->all();
        $this->assertContains($inA->member_no, $nos);
        $this->assertNotContains($inB->member_no, $nos);
        $this->assertSame('Alpha Member', $suggestions->firstWhere('member_no', $inA->member_no)['name']);

        // No samity chosen → no suggestions yet (cascade not complete).
        $response = $this->actingAs($this->admin)->get('/members?branch_id='.$this->talgachi->id);
        $this->assertSame([], $response->viewData('page')['props']['suggestions']);
    }

    // --- CRUD round: edit + lifecycle end ---------------------------------

    public function test_edit_member_and_clearing_share_back_to_zero(): void
    {
        $member = $this->createMember($this->samityA, ['share_amount' => 300]);
        $this->assertEquals(300, (float) $member->share_amount);

        $this->actingAs($this->admin)->put('/members/'.$member->id, [
            'samity_id' => $this->samityB->id,
            'name' => 'Edited Member',
            'gender' => 'Female',
            'mobile' => $member->mobile,
            'share_amount' => '',
            'open_date' => '2026-10-21',
        ])->assertRedirect('/members/'.$member->id);

        $fresh = $member->fresh();
        $this->assertSame('Edited Member', $fresh->name);
        $this->assertSame($this->samityB->id, $fresh->samity_id);
        $this->assertEquals(0, (float) $fresh->share_amount);
    }

    /**
     * FINAL PROOF (owner's finishing requirement): one member created
     * end-to-end through the real routes with the exact payload the
     * fixed form posts — Branch picked first, Samity from that branch,
     * Share Amount left out entirely — then verified visible in the
     * member list AND in the Branch → Samity → Member search cascade.
     */
    public function test_final_proof_member_created_end_to_end_without_share(): void
    {
        // 1) The form's dropdowns are populated (the reported bug).
        $response = $this->actingAs($this->admin)->get('/members/admission');
        $response->assertOk();
        $options = $response->viewData('page')['props']['options'];
        $this->assertContains($this->talgachi->id, collect($options['branches'])->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue(collect($options['samities'])->contains(
            fn ($s) => (int) $s['id'] === $this->samityA->id && (int) $s['branch_id'] === $this->talgachi->id
        ));

        // 2) Submit exactly what the fixed form posts (branch_id rides
        // along from the cascade; share_amount is omitted — left empty).
        $mobile = $this->mobile();
        $this->actingAs($this->admin)->post('/members', [
            'branch_id' => $this->talgachi->id,
            'samity_id' => $this->samityA->id,
            'name' => 'Final Proof Member',
            'gender' => 'Female',
            'mobile' => $mobile,
            'open_date' => '2026-10-21',
        ])->assertRedirect('/members/admission');

        $member = Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
        $this->assertEquals(0, (float) $member->share_amount);
        $this->assertSame($this->talgachi->id, $member->branch_id);
        $this->assertSame($this->samityA->id, $member->samity_id);

        // 3) The member appears in the member list — after switching
        // the active branch to Talgachi through the real switcher
        // route, exactly as the user does in the topbar (the list is
        // active-branch scoped by design).
        $this->actingAs($this->admin)->post('/branch/switch', ['branch_id' => $this->talgachi->id])->assertRedirect();
        $response = $this->actingAs($this->admin)->get('/members');
        $response->assertOk();
        $this->assertContains($member->member_no, collect($response->viewData('page')['props']['members']['data'])->pluck('member_no')->all());

        // 4) …and in the Branch → Samity → Member search cascade.
        $response = $this->actingAs($this->admin)->get('/members?branch_id='.$this->talgachi->id.'&samity_id='.$this->samityA->id);
        $response->assertOk();
        $suggestion = collect($response->viewData('page')['props']['suggestions'])->firstWhere('member_no', $member->member_no);
        $this->assertNotNull($suggestion, 'Created member must appear in the cascade suggestions');
        $this->assertSame('Final Proof Member', $suggestion['name']);
    }

    public function test_member_closing_is_the_lifecycle_end(): void
    {
        $member = $this->createMember($this->samityA, ['name' => 'Closing Member']);

        $this->actingAs($this->admin)->from('/members/closing')->post('/members/closing', [
            'member_id' => $member->id, 'closing_date' => '2026-10-21',
            'closing_reason' => 'Voluntary', 'share_disposition' => 'Refunded',
        ])->assertRedirect('/members/closing');

        $this->assertSame('Closed', $member->fresh()->status);
    }
}
