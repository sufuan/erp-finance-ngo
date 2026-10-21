<?php

namespace Tests\Feature;

use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\LlpService;
use App\Services\LoanAdjustmentService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use App\Services\LoanReportService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Step 20 — loan registers + LLP (net basis, buckets, provision journal). */
class LoanReportsLlpTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private LoanMoneyService $money;
    private LlpService $llp;
    private Member $member;
    private Member $member2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->money = app(LoanMoneyService::class);
        $this->llp = app(LlpService::class);
        $this->member = Member::query()->firstOrFail();
        $this->member2 = Member::query()->skip(1)->firstOrFail();
    }

    private function disbursedLoan(Member $member, array $overrides = []): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open(array_merge([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $overrides), $this->admin);

        return $this->money->disburse($loan, $this->admin);
    }

    public function test_llp_net_basis_standard_bucket_and_journal_rollforward(): void
    {
        $loan = $this->disbursedLoan($this->member);
        // Compulsory savings 2,000 → net basis 10,000; standard @1% = 100.00.
        $csav = DepositProduct::where('code', 'CSAV')->firstOrFail();
        $account = app(DepositAccountService::class)->open($this->member, $csav, [
            'phone' => $this->member->mobile, 'opening_date' => '2026-10-01',
        ], $this->admin->id);
        app(DepositTransactionService::class)->receive($account, '2000', null, null, $this->admin);

        $filters = ['report_date' => '2026-10-21', 'member' => $this->member->member_no];
        $computed = $this->llp->compute($filters);
        $this->assertCount(1, $computed['rows']);
        $this->assertSame('standard', $computed['rows'][0]['bucket']);
        $this->assertSame('10000.00', $computed['rows'][0]['basis']);
        $this->assertSame('100.00', $computed['totals']['required']);

        $run = $this->llp->postRun($filters, $this->admin);
        $this->assertSame('100.00', (string) $run->required_llp);
        $this->assertSame('100.00', (string) $run->actual_llp);
        $this->assertSame('100.00', (string) $run->coverage);
        $this->assertNotNull($run->voucher_id);
        $this->assertSame('100.00', $this->llp->provisionBalance());
        $this->assertTrue(app(PostingService::class)->trialBalance($loan->branch_id)['balanced']);

        // Second identical run: delta zero, no new voucher, balance unchanged.
        $run2 = $this->llp->postRun($filters, $this->admin);
        $this->assertNull($run2->voucher_id);
        $this->assertSame('100.00', $this->llp->provisionBalance());
    }

    public function test_llp_overdue_loan_classifies_substandard_at_25_percent(): void
    {
        $loan = $this->disbursedLoan($this->member2, [
            'loan_amount' => '3000', 'no_of_installments' => 3,
            'installment_from_date' => '2026-09-01',
        ]);
        // Earliest unpaid due 2026-09-01 → 50 days overdue at 2026-10-21.
        $class = $this->llp->classify($loan->fresh(), '2026-10-21');
        $this->assertSame('substandard', $class['bucket']);
        $this->assertSame(50, $class['overdue_days']);
        $computed = $this->llp->compute(['report_date' => '2026-10-21', 'member' => $this->member2->member_no]);
        $this->assertSame('3000.00', $computed['rows'][0]['basis']);
        $this->assertSame('750.00', $computed['totals']['required']); // 25% of 3,000
    }

    public function test_registers_tie_to_transactions(): void
    {
        $loan = $this->disbursedLoan($this->member);
        $this->money->receive($loan, ['amount' => '1083.10', 'penalty_charge' => '0'], $this->admin);
        $reports = app(LoanReportService::class);

        $balance = $reports->run('member_loan_balance', ['loan_type' => 'active']);
        $row = collect($balance['rows'])->firstWhere(2, $loan->loan_number);
        $this->assertNotNull($row);
        $this->assertSame('11066.90', $row[6]); // outstanding principal

        $recovery = $reports->run('loan_recovery_details', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $this->assertCount(1, $recovery['rows']);
        $this->assertSame('1083.10', $recovery['total_row'][8]);

        $samity = $reports->run('samity_loan_outstanding', []);
        $this->assertNotEmpty($samity['rows']);

        $disb = $reports->run('loan_disbursement_register', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $this->assertCount(1, $disb['rows']);

        // Waiver + write-off registers see Step 19 activity.
        $adjust = app(LoanAdjustmentService::class);
        $adjust->waive($loan->fresh(), [
            'waiver_date' => '2026-10-21', 'principal_waive' => '100',
            'profit_waive' => '0', 'charge_waive' => '0', 'comments' => null,
        ], $this->admin);
        $waiver = $reports->run('loan_waiver_report', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $this->assertCount(1, $waiver['rows']);
        $this->assertSame('100.00', $waiver['total_row'][6]);

        $loan2 = $this->disbursedLoan($this->member2, ['loan_amount' => '2000', 'no_of_installments' => 2]);
        $adjust->writeoff($loan2, ['writeoff_date' => '2026-10-21', 'comments' => null], $this->admin);
        $wo = $reports->run('written_off_register', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $this->assertCount(1, $wo['rows']);
        $this->assertSame('2000.00', $wo['rows'][0][3]);
    }

    public function test_pages_render(): void
    {
        $this->actingAs($this->admin)->get('/reports/loans')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Reports/LoanReports'));
        $this->actingAs($this->admin)->get('/reports/loans?report=member_loan_balance&run=1&loan_type=active')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Reports/LoanReports'));
        $this->actingAs($this->admin)->get('/reports/llp')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Reports/Llp'));
        $this->actingAs($this->admin)->get('/reports/loans/member_loan_balance/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
