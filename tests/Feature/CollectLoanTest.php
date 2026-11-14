<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Collect Loan screen (owner order 2026-11-14): a samity-ticked
 * collector opens the panel for a loan in his ticked samity, the
 * amount due is prefilled (the Collection Sheet's receivable figure),
 * one submit posts it through the existing batch path, and the panel
 * counts move. A loan in an unticked samity is not reachable at all —
 * the strict assignment rule of sync #78 applies unchanged.
 */
class CollectLoanTest extends TestCase
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
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeMember(Samity $samity): Member
    {
        $this->memberSeq++;
        $mobile = '0171400000'.$this->memberSeq;
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Collect Loan Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199820000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function disbursedLoan(Member $member): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $this->admin);

        return app(LoanMoneyService::class)->disburse($loan, $this->admin);
    }

    /** A collector whose only samity tick is the Talgachi samity. */
    private function makeCollector(): User
    {
        $this->actingAs($this->admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->post('/settings/roles', [
            'name' => 'collect-loan-collector', 'label' => 'Collect Loan Collector',
            'description' => 'Collects loans in ticked samities',
            'permissions' => ['collection.view', 'collection.collect'],
        ])->assertRedirect()->assertSessionHas('success');
        $this->post('/settings/users', [
            'name' => 'Collect Loan Collector', 'username' => 'collect.loan.collector',
            'email' => 'collect.loan@tpda.example', 'password' => 'Password@123',
            'locale' => 'en', 'roles' => ['collect-loan-collector'],
            'branch_id' => $this->branch->id, 'samity_ids' => [$this->ticked->id],
        ])->assertRedirect()->assertSessionHas('success');

        $user = User::where('username', 'collect.loan.collector')->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function asCollector(User $user): void
    {
        $this->actingAs($user);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_panel_opens_with_auto_due_amount_and_installment_summary(): void
    {
        $loan = $this->disbursedLoan($this->makeMember($this->ticked));
        $collector = $this->makeCollector();
        $this->asCollector($collector);

        $this->get('/collection/collect-loan?loan_id='.$loan->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Collections/CollectLoan')
                ->where('canCollect', true)
                ->where('panel.id', $loan->id)
                ->where('panel.loan_number', $loan->loan_number)
                ->where('panel.due_amount', '1083.10')
                ->where('panel.summary.total', 12)
                ->where('panel.summary.paid_count', 0)
                ->where('panel.summary.remaining_count', 12)
                ->where('panel.summary.collected_amount', '0.00')
                ->has('panel.installments', 12)
                ->where('panel.installments.0.status', 'due')
            );

        // Searching the loan number lands on the same panel directly.
        $this->get('/collection/collect-loan?loan='.$loan->loan_number)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Collections/CollectLoan')
                ->where('panel.id', $loan->id)
                ->where('panel.due_amount', '1083.10')
            );
    }

    public function test_one_click_post_collects_the_prefilled_amount(): void
    {
        $loan = $this->disbursedLoan($this->makeMember($this->ticked));
        $collector = $this->makeCollector();
        $outstandingBefore = app(LoanMoneyService::class)->outstanding($loan)['total'];
        $this->asCollector($collector);

        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => '1083.10'])
            ->assertRedirect('/collection/collect-loan?loan_id='.$loan->id)
            ->assertSessionHas('success', __('collection_posted'));

        $first = LoanSchedule::where('loan_account_id', $loan->id)->where('installment_no', 1)->firstOrFail();
        $this->assertSame('Paid', $first->status, 'First installment fully allocated');
        $outstandingAfter = app(LoanMoneyService::class)->outstanding($loan->fresh())['total'];
        $this->assertSame('1083.10', bcsub($outstandingBefore, $outstandingAfter, 2), 'Outstanding drops by exactly the posted amount');

        // The refreshed panel shows the collection immediately.
        $this->get('/collection/collect-loan?loan_id='.$loan->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Collections/CollectLoan')
                ->where('panel.summary.paid_count', 1)
                ->where('panel.summary.remaining_count', 11)
                ->where('panel.summary.collected_amount', '1083.10')
                ->where('panel.installments.0.status', 'paid')
                ->where('panel.due_amount', '1083.10')
            );
    }

    public function test_loan_in_unticked_samity_is_not_reachable(): void
    {
        $loan = $this->disbursedLoan($this->makeMember($this->unticked));
        $collector = $this->makeCollector();
        $outstandingBefore = app(LoanMoneyService::class)->outstanding($loan)['total'];
        $this->asCollector($collector);

        $this->get('/collection/collect-loan?loan_id='.$loan->id)->assertForbidden();
        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => '1083.10'])
            ->assertForbidden();
        $this->get('/collection/collect-loan?loan='.$loan->loan_number)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Collections/CollectLoan')
                ->where('panel', null)
                ->has('results', 0)
            );
        $this->assertSame($outstandingBefore, app(LoanMoneyService::class)->outstanding($loan->fresh())['total'],
            'Nothing was collected against the unreachable loan');
    }
}
