<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\DepositClosingService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Client requirements 2026-11-18, Feature 2 — the Collect Loan
 * screen becomes the 3-part collection (installment + current
 * savings + long-term savings in ONE batch), the current savings is
 * COMPULSORY with an installment, and members are picked samity-first
 * from a serial list. The daily-collection cap (sync #83) is
 * unchanged.
 */
class CollectionThreePartTest extends TestCase
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

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeMember(Samity $samity): Member
    {
        $this->memberSeq++;
        $mobile = '0171600000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Three Part Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199840000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    /** Loan opened + disbursed; the linked savings account exists (F1). */
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

    private function account(Member $member, string $code): ?DepositAccount
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderBy('id')->first();
    }

    private function openLts(Member $member): DepositAccount
    {
        $product = DepositProduct::where('code', 'LTS')->firstOrFail();
        $this->asAdmin();
        $this->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
            'amount' => 100, 'term_months' => 12,
        ])->assertRedirect('/deposits');

        return $this->account($member, 'LTS');
    }

    public function test_panel_offers_all_three_parts_with_expected_amounts(): void
    {
        $member = $this->makeMember($this->ticked);
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS');
        $savings->update(['installment_amount' => 100]);
        $lts = $this->openLts($member);

        $this->asAdmin();
        $this->get('/collection/collect-loan?loan_id='.$loan->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Collections/CollectLoan')
                ->where('panel.savings.account_id', $savings->id)
                ->where('panel.savings.expected', '100.00')
                ->where('panel.savings.balance', '1200.00')
                ->where('panel.lts.account_id', $lts->id)
                ->where('panel.lts.expected', '100.00')
            );
    }

    public function test_one_click_posts_all_three_parts_in_one_batch(): void
    {
        $member = $this->makeMember($this->ticked);
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS');
        $lts = $this->openLts($member);
        $due = app(CollectionService::class)->loanReceivable($loan);
        $this->assertSame('1083.10', $due);

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due,
            'savings_amount' => '100', 'lts_amount' => '50',
        ])->assertRedirect('/collection/collect-loan?loan_id='.$loan->id)
            ->assertSessionHas('success', __('collection_posted'));

        $batch = CollectionBatch::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame(3, $batch->lines()->where('status', 'Posted')->count(), 'Installment + savings + LTS in one batch');
        $this->assertSame($due, (string) $batch->loan_receive);
        $this->assertSame('150.00', (string) $batch->deposit_receive);
        $first = LoanSchedule::where('loan_account_id', $loan->id)->where('installment_no', 1)->firstOrFail();
        $this->assertSame('Paid', $first->status);
        $this->assertSame('1300.00', (string) $savings->fresh()->current_balance, '1200 disbursement credit + 100 collected');
        $this->assertSame('150.00', (string) $lts->fresh()->current_balance, '100 opening + 50 collected');
    }

    public function test_installment_without_current_savings_is_rejected(): void
    {
        $member = $this->makeMember($this->ticked);
        $loan = $this->disbursedLoan($member);
        $due = app(CollectionService::class)->loanReceivable($loan);

        $this->asAdmin();
        $response = $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => $due]);
        $response->assertSessionHasErrors('lines');
        $errors = session('errors')->get('lines');
        $this->assertStringContainsString(__('savings_required_with_installment'), implode(' ', $errors));

        $first = LoanSchedule::where('loan_account_id', $loan->id)->where('installment_no', 1)->firstOrFail();
        $this->assertNotSame('Paid', $first->status, 'The whole batch rolled back — nothing was collected');
        $this->assertSame(0, CollectionBatch::where('member_id', $member->id)->count());
    }

    public function test_savings_only_collection_is_allowed(): void
    {
        $member = $this->makeMember($this->ticked);
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS');

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => '0', 'savings_amount' => '75',
        ])->assertRedirect()->assertSessionHas('success', __('collection_posted'));
        $this->assertSame('1275.00', (string) $savings->fresh()->current_balance);
    }

    public function test_member_without_a_linked_account_is_exempt(): void
    {
        $member = $this->makeMember($this->ticked);
        $loan = $this->disbursedLoan($member);
        // Close the linked account (balance paid out) → exemption.
        $savings = $this->account($member, 'SAVINGS');
        app(DepositClosingService::class)->close($savings, '2026-11-01', 'Member request', $this->admin, '0');
        $due = app(CollectionService::class)->loanReceivable($loan);

        $this->asAdmin();
        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => $due])
            ->assertRedirect()->assertSessionHas('success', __('collection_posted'));
        $first = LoanSchedule::where('loan_account_id', $loan->id)->where('installment_no', 1)->firstOrFail();
        $this->assertSame('Paid', $first->status);
    }

    public function test_daily_cap_still_holds_inside_the_three_part_post(): void
    {
        $member = $this->makeMember($this->ticked);
        $loan = $this->disbursedLoan($member);
        $due = app(CollectionService::class)->loanReceivable($loan);

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due, 'savings_amount' => '10',
        ])->assertSessionHas('success');
        // Nothing else is due today: a second installment attempt in
        // the same batch shape is refused by the daily rule (#83).
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due, 'savings_amount' => '10',
        ])->assertSessionHasErrors('lines');
        $paid = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count();
        $this->assertSame(1, $paid, 'No future installment was collected in advance');
    }

    public function test_picker_lists_samity_members_serially_with_their_loans(): void
    {
        $first = $this->makeMember($this->ticked);
        $loan = $this->disbursedLoan($first);
        $second = $this->makeMember($this->ticked);

        $this->asAdmin();
        $this->get('/collection/collect-loan?samity_id='.$this->ticked->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Collections/CollectLoan')
                ->where('pickerSamityId', $this->ticked->id)
                ->where('pickerMembers', function ($members) use ($first, $second, $loan) {
                    $members = collect($members);
                    // Serial numbers run 1..N in member_no order.
                    foreach ($members->values() as $i => $row) {
                        $this->assertSame($i + 1, $row['serial']);
                    }
                    $this->assertSame(
                        $members->pluck('member_no')->all(),
                        $members->pluck('member_no')->sort()->values()->all(),
                        'Members are listed serially by member number'
                    );
                    $mine = $members->firstWhere('member_no', $first->member_no);
                    $this->assertNotNull($mine);
                    $this->assertSame($loan->id, $mine['loans'][0]['id']);
                    $other = $members->firstWhere('member_no', $second->member_no);
                    $this->assertSame([], $other['loans']);

                    return true;
                })
            );
    }

    public function test_picker_hides_unticked_samities_from_a_collector(): void
    {
        $member = $this->makeMember($this->unticked);
        $this->disbursedLoan($member);

        // Collector role + user ticked ONLY the Talgachi samity.
        $this->asAdmin();
        $this->post('/settings/roles', [
            'name' => 'three-part-collector', 'label' => 'Three Part Collector',
            'description' => 'Collects in ticked samities',
            'permissions' => ['collection.view', 'collection.collect'],
        ])->assertRedirect()->assertSessionHas('success');
        $this->post('/settings/users', [
            'name' => 'Three Part Collector', 'username' => 'three.part.collector',
            'email' => 'three.part@tpda.example', 'password' => 'Password@123',
            'locale' => 'en', 'roles' => ['three-part-collector'],
            'branch_id' => $this->branch->id, 'samity_ids' => [$this->ticked->id],
        ])->assertRedirect()->assertSessionHas('success');
        $collector = User::where('username', 'three.part.collector')->firstOrFail();
        $collector->forceFill(['must_change_password' => false])->save();

        $this->actingAs($collector);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
        $this->get('/collection/collect-loan?samity_id='.$this->unticked->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Collections/CollectLoan')
                ->has('pickerMembers', 0)
                ->where('samities', fn ($samities) => collect($samities)->doesntContain('id', $this->unticked->id))
            );
    }
}
