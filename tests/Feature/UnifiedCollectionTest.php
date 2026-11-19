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
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Client round 6 (2026-11-19) — ONE unified Collection screen: the
 * separate Member Collection page is retired; the samity-first
 * picker lists every member with something collectable (not only
 * borrowers), a row click opens his panel with only the rows he
 * has, and posting runs through the same CollectionService batch
 * path (compulsory savings + Daily Collection Rule unchanged).
 * Owner refinement: each picker row also carries whether the
 * member has been collected TODAY (আদায় হয়েছে + total / হয়নি),
 * and there is no separate loan/member finder box.
 */
class UnifiedCollectionTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Samity $ticked;

    private User $admin;

    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->ticked = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
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

    /** Admit through the real form (account choice in $extra). */
    private function admit(array $extra = []): Member
    {
        $this->memberSeq++;
        $mobile = '0171800000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', array_merge([
            'samity_id' => $this->ticked->id, 'name' => 'Unified Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199860000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ], $extra))->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function account(Member $member, string $code): ?DepositAccount
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderBy('id')->first();
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

    /** The picker row for one member on the unified screen. */
    private function pickerRow(Member $member): ?array
    {
        $row = null;
        $this->asAdmin();
        $this->get('/collection/collect-loan?samity_id='.$this->ticked->id)->assertOk()
            ->assertInertia(function (AssertableInertia $p) use ($member, &$row) {
                $p->component('Collections/CollectLoan');
                $row = collect($p->toArray()['props']['pickerMembers'])
                    ->firstWhere('member_no', $member->member_no);
            });

        return $row;
    }

    public function test_savings_only_member_is_listed_and_posts_with_collected_today_flag(): void
    {
        $member = $this->admit(['account_type' => 'general', 'savings_amount' => 20]);

        // Listed with his savings due, no loans — and NOT yet
        // collected today.
        $row = $this->pickerRow($member);
        $this->assertNotNull($row, 'A savings-only member appears in the unified picker');
        $this->assertSame([], $row['loans']);
        $this->assertSame('20.00', $row['savings']['expected']);
        $this->assertFalse($row['collected_today']);
        $this->assertSame('0.00', $row['collected_today_amount']);

        // His panel carries NO loan row.
        $this->asAdmin();
        $this->get('/collection/collect-loan?member_id='.$member->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Collections/CollectLoan')
                ->where('memberPanel.member_no', $member->member_no)
                ->where('memberPanel.loan', null)
                ->where('memberPanel.lts', null)
                ->has('memberPanel.savings'));

        // A savings-only collection posts through the unified screen.
        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'savings_amount' => '20',
        ])->assertRedirect('/collection/collect-loan?member_id='.$member->id);
        $this->assertSame('20.00', $this->account($member, 'SAVINGS')->fresh()->current_balance);

        // …and the picker now reports him collected today, total 20.
        $row = $this->pickerRow($member);
        $this->assertTrue($row['collected_today']);
        $this->assertSame('20.00', $row['collected_today_amount']);
    }

    public function test_general_and_lts_rows_post_in_one_batch(): void
    {
        $member = $this->admit([
            'account_type' => 'both', 'savings_amount' => 20,
            'lts_amount' => 100, 'lts_term_years' => 1,
        ]);
        $batchesBefore = CollectionBatch::count();

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'savings_amount' => '20', 'lts_amount' => '100',
        ])->assertRedirect('/collection/collect-loan?member_id='.$member->id);

        $this->assertSame('20.00', $this->account($member, 'SAVINGS')->fresh()->current_balance);
        $this->assertSame('100.00', $this->account($member, 'LTS')->fresh()->current_balance);
        $this->assertSame($batchesBefore + 1, CollectionBatch::count(), 'Both rows land in ONE batch');
        $batch = CollectionBatch::latest('id')->first();
        $this->assertSame(2, $batch->lines()->count());
    }

    public function test_installment_still_requires_savings_and_posts_together(): void
    {
        $member = $this->admit();
        $loan = $this->disbursedLoan($member);

        $due = null;
        $this->asAdmin();
        $this->get('/collection/collect-loan?member_id='.$member->id)->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$due) {
                $p->component('Collections/CollectLoan')->has('memberPanel.loan');
                $due = $p->toArray()['props']['memberPanel']['loan']['due_amount'];
            });
        $this->assertSame('1083.10', $due);

        // Installment alone — the member HAS a general savings
        // account (opened at disbursement), so the compulsory rule
        // rejects the whole batch.
        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'amount' => $due,
        ])->assertSessionHasErrors('lines');
        $this->assertSame('Pending', LoanSchedule::where('loan_account_id', $loan->id)->orderBy('installment_no')->first()->status);

        // Installment + savings together post fine.
        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'amount' => $due, 'savings_amount' => '50',
        ])->assertRedirect('/collection/collect-loan?member_id='.$member->id);
        $this->assertSame('Paid', LoanSchedule::where('loan_account_id', $loan->id)->orderBy('installment_no')->first()->status);
    }

    public function test_withdrawal_on_the_unified_screen_posts_and_respects_the_balance_cap(): void
    {
        $member = $this->admit(['account_type' => 'general', 'savings_amount' => 20]);

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'savings_amount' => '100',
        ])->assertRedirect('/collection/collect-loan?member_id='.$member->id);
        $this->assertSame('100.00', $this->account($member, 'SAVINGS')->fresh()->current_balance);

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'savings_withdrawal_amount' => '40',
        ])->assertRedirect('/collection/collect-loan?member_id='.$member->id);
        $this->assertSame('60.00', $this->account($member, 'SAVINGS')->fresh()->current_balance);

        // Over the balance: refused, balance untouched. A withdrawal
        // alone never flips the collected-today flag either.
        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'savings_withdrawal_amount' => '1000',
        ])->assertSessionHasErrors();
        $this->assertSame('60.00', $this->account($member, 'SAVINGS')->fresh()->current_balance);
    }

    public function test_member_collection_page_is_retired_and_the_unified_screen_serves(): void
    {
        $this->asAdmin();
        // The page is gone: only the POST endpoint remains on that
        // URL (kept for the other flows that post through it), so a
        // GET is refused with 405 rather than serving a page.
        $this->get('/collection/member')->assertStatus(405);
        $this->get('/collection/collect-loan')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Collections/CollectLoan'));
    }
}
