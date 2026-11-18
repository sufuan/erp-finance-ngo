<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Client requirements 2026-11-18, Feature 4 — role split
 * verification: a field worker may CREATE loan proposals without
 * being able to approve them; the branch manager approves. Proposal
 * create and decide are separate permissions (loans.propose vs
 * loans.approve), and the proposal form only finds members inside
 * the worker's ticked samities (strict assignment rule).
 */
class LoanProposalPermissionsTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Samity $ticked;

    private Samity $unticked;

    private User $admin;

    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->ticked = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->unticked = Samity::withoutGlobalScopes()->where('name', 'Talgachi Mohila Samity')->firstOrFail();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->asAdmin();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function asUser(User $user): void
    {
        $this->actingAs($user);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeMember(Samity $samity): Member
    {
        $this->memberSeq++;
        $mobile = '0171800000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Proposal Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199860000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    /** Create a role + user through the real Settings endpoints. */
    private function makeUser(string $slug, array $permissions, array $samityIds): User
    {
        $this->asAdmin();
        $this->post('/settings/roles', [
            'name' => $slug, 'label' => ucwords(str_replace('-', ' ', $slug)),
            'description' => 'Feature 4 verification role',
            'permissions' => $permissions,
        ])->assertRedirect()->assertSessionHas('success');
        $this->post('/settings/users', [
            'name' => ucwords(str_replace('-', ' ', $slug)), 'username' => str_replace('-', '.', $slug),
            'email' => str_replace('-', '.', $slug).'@tpda.example', 'password' => 'Password@123',
            'locale' => 'en', 'roles' => [$slug],
            'branch_id' => $this->branch->id, 'samity_ids' => $samityIds,
        ])->assertRedirect()->assertSessionHas('success');
        $user = User::where('username', str_replace('-', '.', $slug))->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function proposalPayload(Member $member): array
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();

        return [
            'member_id' => $member->id, 'loan_product_id' => $product->id,
            'proposal_date' => '2026-10-21', 'loan_purpose' => 'Agriculture',
            'loan_amount' => '10000', 'frequency' => 'M', 'no_of_installments' => 12,
            'installment_from_date' => '2026-11-01', 'guarantors' => [],
        ];
    }

    public function test_field_worker_creates_but_cannot_approve_and_manager_approves(): void
    {
        $member = $this->makeMember($this->ticked);
        $worker = $this->makeUser('field-worker-f4', ['loans.view', 'loans.propose'], [$this->ticked->id]);
        $manager = $this->makeUser('branch-manager-f4', ['loans.view', 'loans.approve'], [$this->ticked->id, $this->unticked->id]);

        // The worker CAN create a proposal for a member of his samity.
        $this->asUser($worker);
        $this->post('/loans/proposals', $this->proposalPayload($member))
            ->assertRedirect()->assertSessionHas('success');
        $proposal = LoanProposal::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame('Submitted', $proposal->status);

        // …but deciding it is a separate permission he does not hold.
        $this->post('/loans/proposals/'.$proposal->id.'/approve')->assertForbidden();
        $this->post('/loans/proposals/'.$proposal->id.'/reject')->assertForbidden();
        $this->assertSame('Submitted', $proposal->fresh()->status);

        // The manager (approve, no propose) sees it in his list and
        // approves it — but cannot create proposals himself.
        $this->asUser($manager);
        $this->get('/loans/proposals?status=Submitted')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Loans/ProposalList')
                ->where('canApprove', true)
                ->where('proposals', fn ($rows) => collect($rows)->contains('id', $proposal->id))
            );
        $this->post('/loans/proposals', $this->proposalPayload($member))->assertForbidden();
        $this->post('/loans/proposals/'.$proposal->id.'/approve')
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Approved', $proposal->fresh()->status);
    }

    public function test_proposal_form_finds_only_ticked_samity_members(): void
    {
        $mine = $this->makeMember($this->ticked);
        $other = $this->makeMember($this->unticked);
        $worker = $this->makeUser('field-worker-scope', ['loans.view', 'loans.propose'], [$this->ticked->id]);

        $this->asUser($worker);
        $this->get('/loans/proposals/create?member='.$mine->member_no)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Loans/ProposalCreate')
                ->where('member.id', $mine->id)
            );
        $this->get('/loans/proposals/create?member='.$other->member_no)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Loans/ProposalCreate')
                ->where('member', null)
            );
        // And a store attempt against the unticked member fails too
        // (the member is invisible to him — the scoped lookup 404s).
        $this->post('/loans/proposals', $this->proposalPayload($other))
            ->assertNotFound();
        $this->assertSame(0, LoanProposal::where('member_id', $other->id)->count());
    }
}
