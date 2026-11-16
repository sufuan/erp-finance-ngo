<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanReceive;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\CollectionService;
use App\Services\DepositAccountService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Daily loan, daily collection (owner rule 2026-11-16): an installment
 * may be collected ONLY against amounts due on or before the current
 * business date (arrears + today's due). The Collect Loan screen used
 * to prefill the NEXT installment when nothing was due, and the shared
 * posting path never capped a loan line at the due-to-date receivable,
 * so repeated Collect clicks ate future installments as "advance".
 * These tests pin the rule at the real endpoints: Collect Loan,
 * Collection Sheet — partial payment of a due amount and arrears stay
 * collectable; anything beyond the due-to-date total is rejected and
 * posts nothing.
 *
 * Loans are daily loans whose schedule due dates are pinned onto /
 * around the branch's real business date, so the expectations hold on
 * any run date. Expected amounts are read from the generated schedule
 * rows (ScheduleEngine's truth), never from the receivable under test.
 */
class DailyLoanCollectionRuleTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Samity $samity;

    private User $admin;

    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->samity = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
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

    private function bizDate(): string
    {
        return app(BusinessDayService::class)->currentDate($this->branch->id);
    }

    private function bizShift(int $days): string
    {
        return CarbonImmutable::parse($this->bizDate())->addDays($days)->toDateString();
    }

    private function makeMember(): Member
    {
        $this->memberSeq++;
        $mobile = '0171500000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', [
            'samity_id' => $this->samity->id, 'name' => 'Daily Rule Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199720000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => $this->bizShift(-30),
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    /**
     * A disbursed daily loan whose installment due dates are pinned to
     * exactly the given dates (the schedule engine shifts generated
     * dates off weekends, so the fixture pins them afterwards — the
     * same trick DirectorDashboardTest uses).
     *
     * @param  string[]  $dueDates
     */
    private function loanWithDueDates(Member $member, array $dueDates): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => $this->bizShift(-30),
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'D', 'no_of_installments' => count($dueDates), 'profit_rate' => null,
            'installment_from_date' => $dueDates[0], 'fees' => [], 'guarantors' => [],
        ], $this->admin);
        $loan = app(LoanMoneyService::class)->disburse($loan, $this->admin);
        foreach ($dueDates as $i => $date) {
            LoanSchedule::where('loan_account_id', $loan->id)
                ->where('installment_no', $i + 1)->update(['due_date' => $date]);
        }

        return $loan->fresh();
    }

    /** Sum of total_due over the loan's first $count schedule rows. */
    private function dueOfFirst(LoanAccount $loan, int $count): string
    {
        $sum = LoanSchedule::where('loan_account_id', $loan->id)
            ->where('installment_no', '<=', $count)->sum('total_due');

        return bcadd((string) $sum, '0', 2);
    }

    private function outstanding(LoanAccount $loan): string
    {
        return app(LoanMoneyService::class)->outstanding($loan->fresh())['total'];
    }

    /** The session 'lines' error text of the last response, flattened. */
    private function linesErrorText(): string
    {
        $errors = session('errors');

        return $errors ? collect($errors->get('lines') ?? [])->flatten()->implode(' ') : '';
    }

    public function test_collecting_todays_due_twice_is_rejected_the_second_time(): void
    {
        $member = $this->makeMember();
        // Only installment 1 falls due today; 2 and 3 are future.
        $loan = $this->loanWithDueDates($member, [$this->bizDate(), $this->bizShift(1), $this->bizShift(2)]);
        $dueToday = $this->dueOfFirst($loan, 1);
        $outstandingBefore = $this->outstanding($loan);
        $batchesBefore = CollectionBatch::count();

        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => $dueToday])
            ->assertRedirect('/collection/collect-loan?loan_id='.$loan->id)
            ->assertSessionHas('success', __('collection_posted'));
        $this->assertSame('Paid', LoanSchedule::where('loan_account_id', $loan->id)
            ->where('installment_no', 1)->value('status'));
        $this->assertSame(bcsub($outstandingBefore, $dueToday, 2), $this->outstanding($loan));
        $this->assertSame($batchesBefore + 1, CollectionBatch::count());

        // The panel now reports nothing due today.
        $this->get('/collection/collect-loan?loan_id='.$loan->id)->assertOk()
            ->assertInertia(fn ($p) => $p->component('Collections/CollectLoan')
                ->where('panel.due_amount', '0.00'));

        // A second click the same business day must NOT eat the next
        // (future) installment as an advance.
        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => $dueToday])
            ->assertSessionHasErrors(['lines']);
        $this->assertStringContainsString(__('loan_no_due_today'), $this->linesErrorText());
        $this->assertSame(bcsub($outstandingBefore, $dueToday, 2), $this->outstanding($loan),
            'Outstanding must not move on the rejected second collect');
        $this->assertSame($batchesBefore + 1, CollectionBatch::count(), 'No new batch may be posted');
        $this->assertSame(1, LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->count());
        $this->assertSame('Pending', LoanSchedule::where('loan_account_id', $loan->id)
            ->where('installment_no', 2)->value('status'), 'Future installment stays untouched');
    }

    public function test_arrears_collectable_in_full_then_further_collect_rejected(): void
    {
        $member = $this->makeMember();
        // Three installments due (two past + today), two still future.
        $loan = $this->loanWithDueDates($member, [
            $this->bizShift(-2), $this->bizShift(-1), $this->bizDate(), $this->bizShift(1), $this->bizShift(2),
        ]);
        $receivable = $this->dueOfFirst($loan, 3);
        $this->assertSame($receivable, app(CollectionService::class)->loanReceivable($loan->fresh()),
            'Receivable = arrears + today, nothing from the future');

        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => $receivable])
            ->assertRedirect('/collection/collect-loan?loan_id='.$loan->id)
            ->assertSessionHas('success', __('collection_posted'));
        foreach ([1, 2, 3] as $no) {
            $this->assertSame('Paid', LoanSchedule::where('loan_account_id', $loan->id)
                ->where('installment_no', $no)->value('status'), "Installment {$no} paid");
        }
        $outstandingAfter = $this->outstanding($loan);

        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => '100'])
            ->assertSessionHasErrors(['lines']);
        $this->assertStringContainsString(__('loan_no_due_today'), $this->linesErrorText());
        $this->assertSame($outstandingAfter, $this->outstanding($loan));
        $this->assertSame(1, LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->count());
    }

    public function test_partial_payment_leaves_remainder_collectable_the_same_day(): void
    {
        $member = $this->makeMember();
        $loan = $this->loanWithDueDates($member, [$this->bizDate(), $this->bizShift(1)]);
        $dueToday = $this->dueOfFirst($loan, 1);
        $partial = bcsub($dueToday, '400', 2);
        $this->assertTrue(bccomp($partial, '0', 2) === 1, 'Fixture installment must exceed 400');

        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => $partial])
            ->assertRedirect('/collection/collect-loan?loan_id='.$loan->id)
            ->assertSessionHas('success', __('collection_posted'));

        // The remainder of today's due is still collectable today.
        $this->get('/collection/collect-loan?loan_id='.$loan->id)->assertOk()
            ->assertInertia(fn ($p) => $p->component('Collections/CollectLoan')
                ->where('panel.due_amount', '400.00'));
        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => '400'])
            ->assertRedirect('/collection/collect-loan?loan_id='.$loan->id)
            ->assertSessionHas('success', __('collection_posted'));
        $this->assertSame('Paid', LoanSchedule::where('loan_account_id', $loan->id)
            ->where('installment_no', 1)->value('status'));

        // Once the due is fully covered, the day is done for this loan.
        $this->post('/collection/collect-loan', ['loan_id' => $loan->id, 'amount' => '100'])
            ->assertSessionHasErrors(['lines']);
        $this->assertStringContainsString(__('loan_no_due_today'), $this->linesErrorText());
        $this->assertSame(2, LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->count());
    }

    public function test_sheet_posting_rejects_loan_line_above_the_due_to_date_receivable(): void
    {
        $member = $this->makeMember();
        $savings = $this->openSavings($member);
        $loan = $this->loanWithDueDates($member, [$this->bizDate(), $this->bizShift(1)]);
        $dueToday = $this->dueOfFirst($loan, 1);
        // Due today + the whole future installment on top of it.
        $tooMuch = $this->dueOfFirst($loan, 2);
        $this->assertTrue(bccomp($tooMuch, $dueToday, 2) === 1, 'Fixture: two installments exceed one');
        $batchesBefore = CollectionBatch::count();

        // One bad loan line must roll the WHOLE sheet back — the valid
        // deposit line in the same sheet posts nothing either.
        $this->post('/collection/sheet?samity_id='.$this->samity->id, [
            'samity_id' => $this->samity->id,
            'lines' => [
                ['member_id' => $member->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '200'],
                ['member_id' => $member->id, 'account_type' => 'loan', 'account_id' => $loan->id, 'amount' => $tooMuch],
            ],
        ])->assertSessionHasErrors(['lines']);
        $this->assertStringContainsString(__('loan_collection_exceeds_due'), $this->linesErrorText());
        $this->assertSame($batchesBefore, CollectionBatch::count(), 'The whole sheet rolls back');
        $this->assertSame('0.00', (string) $savings->fresh()->current_balance, 'Deposit line rolled back too');
        $this->assertSame(0, LoanReceive::where('loan_account_id', $loan->id)->count());
    }

    public function test_sheet_posting_rejects_a_future_installment_when_nothing_is_due(): void
    {
        $member = $this->makeMember();
        // Every installment is in the future — nothing may be collected.
        $loan = $this->loanWithDueDates($member, [$this->bizShift(1), $this->bizShift(2)]);
        $oneInstallment = $this->dueOfFirst($loan, 1);
        $this->assertSame('0.00', app(CollectionService::class)->loanReceivable($loan->fresh()),
            'No fallback to the next installment');
        $batchesBefore = CollectionBatch::count();

        $this->post('/collection/sheet?samity_id='.$this->samity->id, [
            'samity_id' => $this->samity->id,
            'lines' => [
                ['member_id' => $member->id, 'account_type' => 'loan', 'account_id' => $loan->id, 'amount' => $oneInstallment],
            ],
        ])->assertSessionHasErrors(['lines']);
        $this->assertStringContainsString(__('loan_no_due_today'), $this->linesErrorText());
        $this->assertSame($batchesBefore, CollectionBatch::count());
        $this->assertSame(0, LoanReceive::where('loan_account_id', $loan->id)->count());
    }

    private function openSavings(Member $member): DepositAccount
    {
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();

        return app(DepositAccountService::class)->open($member, $product, [
            'phone' => $member->mobile, 'opening_date' => $this->bizShift(-30),
            'installment_amount' => null,
        ], $this->admin->id);
    }
}
