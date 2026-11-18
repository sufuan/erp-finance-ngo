<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\Branch;
use App\Models\BusinessDay;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\AccountReportService;
use App\Services\BusinessDayService;
use App\Services\CollectionService;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use App\Services\PostingService;
use App\Services\RegisterReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Client round 3 (2026-11-18) — Parts A/B/C: the manager's daily
 * register, the weekly balance sheet, and the Director-only
 * all-branches register list.
 *
 * The scenario runs on a FRESH branch (code 901) whose business days
 * are pinned to 2026-11-01…2026-11-08, so every figure is produced
 * by this test alone:
 *
 *   11-01 (pre-week): 6 admissions (shares 500 + 300); savings
 *     receives 100,000 (A) + 5,000 (D) + 100,000 (C); loan L1 12,000
 *     disbursed for B with insurance 120 + form fee 50 (its 10%
 *     linked-savings credit adds 1,200 to the day's savings).
 *   11-02: H admitted (share 300); A +20,000, C +20,000.
 *   11-03: L1 installment #1 (1,083.10) collected with B's savings
 *     100 through the real collect-loan route; A withdraws 6,000
 *     and D withdraws 4,000 (office payments).
 *   11-04: C withdraws 10,000; office expense voucher 700.
 *   11-05: loan L2 6,000 disbursed for E (linked credit 600).
 *   11-06: L2 settled in full through the office receive path.
 *   11-07: member G closed.  11-08: nothing.
 *
 * The week for Part B ends 11-07 → week = 11-02…11-07. Samity Two
 * reproduces the client's own example exactly: savings 1,00,000
 * before the week + 20,000 in − 10,000 out = 1,10,000.
 */
class ManagerRegisterTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Samity $samityOne;

    private Samity $samityTwo;

    private User $admin;

    private int $memberSeq = 0;

    /** @var array<string, Member> */
    private array $members = [];

    private LoanAccount $loanOne;

    private LoanAccount $loanTwo;

    private string $loanTwoPayable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');

        $this->branch = Branch::create([
            'code' => '901', 'name' => 'Register Test Branch', 'type' => 'Branch',
            'opening_date' => '2026-11-01', 'country' => 'Bangladesh', 'status' => 'Active',
        ]);
        BusinessDay::create([
            'branch_id' => $this->branch->id, 'business_date' => '2026-11-01', 'is_closed' => false,
        ]);
        $this->samityOne = Samity::create([
            'samity_code' => '9001', 'name' => 'Register Samity One',
            'branch_id' => $this->branch->id, 'status' => 'Active', 'open_date' => '2026-11-01',
        ]);
        $this->samityTwo = Samity::create([
            'samity_code' => '9002', 'name' => 'Register Samity Two',
            'branch_id' => $this->branch->id, 'status' => 'Active', 'open_date' => '2026-11-01',
        ]);
        $this->asAdmin();
        $this->runScenario();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function advanceDay(): void
    {
        app(BusinessDayService::class)->close($this->branch->id, $this->admin->id);
    }

    private function admit(string $key, Samity $samity, int $share, string $openDate): Member
    {
        $this->memberSeq++;
        $mobile = '0171800000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', [
            'branch_id' => $this->branch->id, 'samity_id' => $samity->id,
            'name' => 'Register Member '.$key,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199860000'.$this->memberSeq,
            'share_amount' => $share, 'open_date' => $openDate,
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return $this->members[$key] = Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function openSavings(Member $member, int $installment, string $receive): DepositAccount
    {
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $account = app(DepositAccountService::class)->open($member, $product, [
            'opening_date' => app(BusinessDayService::class)->currentDate($this->branch->id),
            'installment_amount' => $installment,
        ], $this->admin->id);
        if ($receive !== '0') {
            app(DepositTransactionService::class)->receive($account, $receive, null, 'Scenario deposit', $this->admin);
        }

        return $account;
    }

    private function openLoan(Member $member, string $amount, int $installments, string $from, array $fees, string $disbursedAt): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id,
            'opening_date' => app(BusinessDayService::class)->currentDate($this->branch->id),
            'loan_purpose' => 'Agriculture', 'loan_amount' => $amount, 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => $installments, 'profit_rate' => null,
            'installment_from_date' => $from, 'fees' => $fees, 'guarantors' => [],
        ], $this->admin);
        $loan = app(LoanMoneyService::class)->disburse($loan, $this->admin);
        // disbursed_at is a wall-clock stamp; pin it to the scenario
        // business day so the dated reports see the disbursement on
        // the day the money moved in business terms.
        $loan->update(['disbursed_at' => $disbursedAt.' 10:00:00']);

        return $loan->fresh();
    }

    private function payable(LoanAccount $loan): string
    {
        return (string) LoanSchedule::where('loan_account_id', $loan->id)->whereNull('superseded_by')
            ->selectRaw('COALESCE(SUM(principal_due + profit_due), 0) AS t')->value('t');
    }

    private function runScenario(): void
    {
        // ---- 2026-11-01 (pre-week) -------------------------------------
        $a = $this->admit('A', $this->samityOne, 500, '2026-11-01');
        $b = $this->admit('B', $this->samityOne, 0, '2026-11-01');
        $d = $this->admit('D', $this->samityOne, 0, '2026-11-01');
        $e = $this->admit('E', $this->samityOne, 0, '2026-11-01');
        $g = $this->admit('G', $this->samityTwo, 0, '2026-11-01');
        $c = $this->admit('C', $this->samityTwo, 300, '2026-11-01');
        $accountA = $this->openSavings($a, 100, '100000');
        $accountD = $this->openSavings($d, 50, '5000');
        $accountC = $this->openSavings($c, 200, '100000');
        $this->loanOne = $this->openLoan($b, '12000', 12, '2026-11-03', ['insurance' => '120', 'form' => '50'], '2026-11-01');

        // ---- 2026-11-02 -------------------------------------------------
        $this->advanceDay();
        $this->admit('H', $this->samityTwo, 300, '2026-11-02');
        app(DepositTransactionService::class)->receive($accountA, '20000', null, 'Weekly deposit', $this->admin);
        app(DepositTransactionService::class)->receive($accountC, '20000', null, 'Weekly deposit', $this->admin);

        // ---- 2026-11-03 -------------------------------------------------
        $this->advanceDay();
        $due = app(CollectionService::class)->loanReceivable($this->loanOne);
        $this->assertSame('1083.10', $due, 'Scenario anchor: installment #1 of L1');
        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $this->loanOne->id, 'amount' => $due, 'savings_amount' => '100',
        ])->assertSessionHas('success', __('collection_posted'));
        app(DepositTransactionService::class)->payment($accountA, '6000', null, 'Withdrawal', $this->admin);
        app(DepositTransactionService::class)->payment($accountD, '4000', null, 'Withdrawal', $this->admin);

        // ---- 2026-11-04 -------------------------------------------------
        $this->advanceDay();
        app(DepositTransactionService::class)->payment($accountC, '10000', null, 'Withdrawal', $this->admin);
        app(PostingService::class)->post([
            'branch_id' => $this->branch->id, 'voucher_type' => 'Payment', 'entry_by' => $this->admin->id,
            'comments' => 'Office expense',
            'lines' => [['mapping_key' => 'cash.expense', 'amount' => '700']],
        ]);

        // ---- 2026-11-05 -------------------------------------------------
        $this->advanceDay();
        $this->loanTwo = $this->openLoan($e, '6000', 6, '2026-12-01', [], '2026-11-05');
        $this->loanTwoPayable = $this->payable($this->loanTwo);

        // ---- 2026-11-06 -------------------------------------------------
        $this->advanceDay();
        $outstanding = app(LoanMoneyService::class)->outstanding($this->loanTwo);
        app(LoanMoneyService::class)->receive($this->loanTwo, ['amount' => $outstanding['total']], $this->admin);
        $this->assertSame('Closed', $this->loanTwo->fresh()->status);

        // ---- 2026-11-07 -------------------------------------------------
        $this->advanceDay();
        $this->asAdmin();
        $this->post('/members/closing', [
            'member_id' => $g->id, 'closing_date' => '2026-11-07',
            'closing_reason' => 'Voluntary', 'share_disposition' => 'Refunded',
        ])->assertSessionHas('success');

        // ---- 2026-11-08 -------------------------------------------------
        $this->advanceDay();
    }

    /** @return array<string, array<string, mixed>> register rows keyed by date */
    private function registerRows(): array
    {
        $data = app(RegisterReportService::class)->dailyRegister($this->branch->id, '2026-11');
        $rows = [];
        foreach ($data['weeks'] as $week) {
            foreach ($week['rows'] as $row) {
                $rows[$row['date']] = $row;
            }
        }

        return [$rows, $data];
    }

    public function test_daily_register_figures_match_the_scenario(): void
    {
        [$rows, $data] = $this->registerRows();
        $this->assertSame(
            ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'],
            array_keys($rows),
            'One row per business day of the month'
        );
        $this->assertCount(2, $data['weeks'], '8 business days chunk into 6 + 2');
        $payableOne = $this->payable($this->loanOne);
        $this->assertSame('12997.20', $payableOne, 'Scenario anchor: L1 total repayable');

        // --- 11-01: admissions, big deposits, L1 out with fees ---------
        $r = $rows['2026-11-01'];
        $this->assertSame('350.00', $r['savings_due'], 'installments 100 + 50 + 200 of the three savings accounts');
        $this->assertSame('206200.00', $r['savings_collected'], '100,000 + 5,000 + 100,000 + the 1,200 linked credit');
        $this->assertSame(6, $r['admission_count']);
        $this->assertSame('800.00', $r['admission_amount'], 'shares 500 (A) + 300 (C)');
        $this->assertSame(1, $r['insurance_count']);
        $this->assertSame('120.00', $r['insurance_amount']);
        $this->assertSame('50.00', $r['other_fees'], 'the form fee');
        $this->assertSame(0, $r['withdrawal_count']);
        $this->assertSame('207170.00', $r['total_in'], '206,200 + 800 + 120 + 50 − 0');
        $this->assertSame(1, $r['disbursed_count']);
        $this->assertSame('12000.00', $r['disbursed_amount']);
        $this->assertSame('0.00', $r['installment_due'], 'first installment falls due 11-03');
        $this->assertSame($payableOne, $r['loan_outstanding']);
        $this->assertSame('0.00', $r['other_expenses']);
        $cash = Ledger::where('ledger_code', '1010')->firstOrFail();
        $this->assertSame(
            app(AccountReportService::class)->balanceUpTo([$this->branch->id], $cash, '2026-11-01'),
            $r['cash_in_hand'],
            'হাতে নগদ is the cash-book balance of ledger 1010'
        );

        // --- 11-02: one admission, two weekly deposits ------------------
        $r = $rows['2026-11-02'];
        $this->assertSame(1, $r['admission_count']);
        $this->assertSame('300.00', $r['admission_amount']);
        $this->assertSame('40000.00', $r['savings_collected']);

        // --- 11-03: installment day + two withdrawals -------------------
        $r = $rows['2026-11-03'];
        $this->assertSame('1083.10', $r['installment_due']);
        $this->assertSame('1083.10', $r['installment_collected']);
        $this->assertSame('100.00', $r['savings_collected'], "B's compulsory savings");
        $this->assertSame(2, $r['withdrawal_count']);
        $this->assertSame('10000.00', $r['withdrawal_amount']);
        $this->assertSame('-9900.00', $r['total_in'], '100 in − 10,000 out');
        $this->assertSame('1083.10', $r['total_loan']);
        $this->assertSame('11914.10', $r['loan_outstanding'], '12,997.20 − 1,083.10');

        // --- 11-04: C's withdrawal + office expense ----------------------
        $r = $rows['2026-11-04'];
        $this->assertSame(1, $r['withdrawal_count']);
        $this->assertSame('10000.00', $r['withdrawal_amount']);
        $this->assertSame('700.00', $r['other_expenses']);

        // --- 11-05: L2 disbursed -----------------------------------------
        $r = $rows['2026-11-05'];
        $this->assertSame(1, $r['disbursed_count']);
        $this->assertSame('6000.00', $r['disbursed_amount']);
        $this->assertSame('600.00', $r['savings_collected'], "L2's 10% linked credit");

        // --- 11-06: L2 settled — counted ONCE, under পরিশোধ ---------------
        $r = $rows['2026-11-06'];
        $this->assertSame(1, $r['settled_count']);
        $this->assertSame($this->loanTwoPayable, $r['settled_amount']);
        $this->assertSame('0.00', $r['installment_collected'], 'settlement money is not double-counted as installment collection');
        $this->assertSame($this->loanTwoPayable, $r['total_loan']);
        $this->assertSame('11914.10', $r['loan_outstanding'], 'only L1 remains');

        // --- 11-07: member exit; 11-08: quiet day -------------------------
        $this->assertSame(1, $rows['2026-11-07']['member_exit_count']);
        $r = $rows['2026-11-08'];
        $this->assertSame('0.00', $r['savings_collected']);
        $this->assertSame('11914.10', $r['loan_outstanding'], 'balances carry to the last day');

        // --- grand total row ----------------------------------------------
        $grand = $data['grand'];
        $this->assertSame('246900.00', $grand['savings_collected'], '206,200 + 40,000 + 100 + 600');
        $this->assertSame('1100.00', $grand['admission_amount']);
        $this->assertSame('11914.10', $grand['loan_outstanding'], 'grand row carries the LAST day balance');

        // --- per-week সর্বমোট rows are CUMULATIVE, as on the sheet --------
        $weekOne = $data['weeks'][0];
        $this->assertSame('246900.00', $weekOne['cumulative']['savings_collected'], 'days 11-07/11-08 add no savings, so week 1 already reaches the month figure');
        $this->assertSame('11914.10', $weekOne['cumulative']['loan_outstanding'], 'cumulative balances come from the week\'s last day (11-06)');
        $this->assertSame($grand, $data['weeks'][1]['cumulative'], 'the last সর্বমোট row IS the month grand total');
    }

    public function test_weekly_balance_sheet_matches_the_client_example(): void
    {
        $data = app(RegisterReportService::class)->weeklyBalance($this->branch->id, '2026-11-07');
        $this->assertSame('2026-11-02', $data['week']['start']);
        $this->assertSame('2026-11-07', $data['week']['end']);

        $savings = collect($data['savings'])->keyBy('samity_code');
        $one = $savings['9001'];
        $this->assertSame('106200.00', $one['previous_balance'], '100,000 + 5,000 + 1,200 before the week');
        $this->assertSame('20700.00', $one['weekly_collection'], '20,000 + 100 + 600 during the week');
        $this->assertSame(2, $one['withdrawal_count']);
        $this->assertSame('10000.00', $one['withdrawal_amount']);
        $this->assertSame('116900.00', $one['total_balance']);

        // The client's own example, digit for digit.
        $two = $savings['9002'];
        $this->assertSame('100000.00', $two['previous_balance']);
        $this->assertSame('20000.00', $two['weekly_collection']);
        $this->assertSame(1, $two['withdrawal_count']);
        $this->assertSame('10000.00', $two['withdrawal_amount']);
        $this->assertSame('110000.00', $two['total_balance'], '1,00,000 + 20,000 − 10,000 = 1,10,000');

        $total = $data['savings_total'];
        $this->assertSame('206200.00', $total['previous_balance']);
        $this->assertSame('40700.00', $total['weekly_collection']);
        $this->assertSame('20000.00', $total['withdrawal_amount']);
        $this->assertSame('226900.00', $total['total_balance']);

        $loans = collect($data['loans'])->keyBy('samity_code');
        $one = $loans['9001'];
        $this->assertSame('12997.20', $one['previous_dues']);
        $collected = (string) \Brick\Math\BigDecimal::of('1083.10')->plus($this->loanTwoPayable)->toScale(2);
        $this->assertSame($collected, $one['weekly_collection'], "L1's installment + L2's full settlement");
        $this->assertSame(1, $one['disbursed_count']);
        $this->assertSame($this->loanTwoPayable, $one['disbursed_amount'], 'new loans enter at total repayable so the balance identity holds');
        $this->assertSame('11914.10', $one['total_outstanding'], '12,997.20 − collected + disbursed = L1 remainder');
        $two = $loans['9002'];
        $this->assertSame('0.00', $two['previous_dues']);
        $this->assertSame('0.00', $two['total_outstanding']);
    }

    public function test_register_pages_render_and_print(): void
    {
        $this->asAdmin();
        $this->get('/reports/manager-register?branch_id='.$this->branch->id.'&month=2026-11')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Reports/ManagerRegister')
                ->where('branch.id', $this->branch->id)
                ->has('weeks', 2));
        $this->get('/reports/weekly-balance?branch_id='.$this->branch->id.'&week_end=2026-11-07')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Reports/WeeklyBalance')
                ->where('week.start', '2026-11-02')
                ->where('week.end', '2026-11-07'));

        foreach (['/reports/manager-register/pdf?branch_id='.$this->branch->id.'&month=2026-11',
            '/reports/weekly-balance/pdf?branch_id='.$this->branch->id.'&week_end=2026-11-07'] as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_branch_registers_list_is_director_only(): void
    {
        $director = User::factory()->create(['must_change_password' => false]);
        $director->assignRole('director');
        $this->actingAs($director);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->get('/reports/branch-registers')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Reports/BranchRegisters')
                ->where('branches', fn ($branches) => collect($branches)->contains('id', $this->branch->id)));

        // The Director may open ANY branch's register (Part C).
        $this->get('/reports/manager-register?branch_id='.$this->branch->id.'&month=2026-11')->assertOk();
        $pdf = $this->get('/reports/manager-register/pdf?branch_id='.$this->branch->id.'&month=2026-11');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $manager = User::factory()->create(['must_change_password' => false]);
        $manager->assignRole('branch-manager');
        $this->actingAs($manager);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->get('/reports/branch-registers')->assertForbidden();
    }

    public function test_branch_manager_picker_is_limited_to_his_own_branch(): void
    {
        $other = Branch::where('code', '102')->firstOrFail();
        $manager = User::factory()->create(['must_change_password' => false]);
        $manager->assignRole('branch-manager');
        $manager->branches()->attach($other->id, ['include_children' => false]);

        $this->actingAs($manager);
        $this->withSession([
            'active_branch_id' => $other->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
        // His own branch opens; the register-test branch is refused
        // and absent from his picker options.
        $this->get('/reports/manager-register')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Reports/ManagerRegister')
                ->where('branch.id', $other->id)
                ->where('branches', fn ($branches) => collect($branches)->doesntContain('id', $this->branch->id)));
        $this->get('/reports/manager-register?branch_id='.$this->branch->id)->assertForbidden();
        $this->get('/reports/weekly-balance?branch_id='.$this->branch->id)->assertForbidden();
    }

    public function test_plain_user_without_reports_or_director_permission_gets_403(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->get('/reports/manager-register')->assertForbidden();
        $this->get('/reports/weekly-balance')->assertForbidden();
    }
}
