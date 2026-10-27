<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\DepositProduct;
use App\Models\DuePenaltySetup;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Step 18 — disbursement, FIFO receive, latest-only cancel, deposit transfer, closure refund. */
class LoanMoneyTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private LoanMoneyService $money;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->money = app(LoanMoneyService::class);
        $this->member = Member::query()->firstOrFail();
    }

    private function disbursedLoan(array $overrides = []): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open(array_merge([
            'member_id' => $this->member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $overrides), $this->admin);

        return $this->money->disburse($loan, $this->admin);
    }

    private function loanLedgerBalance(LoanAccount $loan): string
    {
        return (string) AccountTransaction::where('account_type', 'loan')
            ->where('account_id', $loan->id)->whereNull('cancel_at')
            ->orderByDesc('id')->value('balance_after') ?? '0.00';
    }

    public function test_disburse_then_fifo_receive_then_cancel_latest_only(): void
    {
        $loan = $this->disbursedLoan();
        $this->assertSame('Disbursed', $loan->status);
        $this->assertSame('12000.00', $this->loanLedgerBalance($loan));
        $tb = app(PostingService::class)->trialBalance($loan->branch_id);
        $this->assertTrue($tb['balanced']);

        // First installment: profit 150.00 first, then principal 933.10.
        $r1 = $this->money->receive($loan, ['amount' => '1083.10', 'penalty_charge' => '0'], $this->admin);
        $this->assertSame('150.00', (string) $r1->profit_amount);
        $this->assertSame('933.10', (string) $r1->investment_amount);
        $row1 = $loan->schedules()->where('installment_no', 1)->first();
        $this->assertSame('Paid', $row1->status);
        $this->assertSame('Active', $loan->fresh()->status);
        $this->assertSame('11066.90', $this->loanLedgerBalance($loan));

        // Second installment: profit on 11,066.90 @1.25% = 138.34.
        $r2 = $this->money->receive($loan->fresh(), ['amount' => '1083.10', 'penalty_charge' => '0'], $this->admin);
        $this->assertSame('138.34', (string) $r2->profit_amount);
        $this->assertSame('944.76', (string) $r2->investment_amount);

        // Cancelling the OLDER receipt is refused; the latest cancels cleanly.
        try {
            $this->money->cancelLatest($r1, $this->admin, 'Wrong entry');
            $this->fail('Older receipt cancel should be refused.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->money->cancelLatest($r2, $this->admin, 'Wrong entry');
        $row2 = $loan->schedules()->where('installment_no', 2)->first();
        $this->assertSame('Pending', $row2->status);
        $this->assertSame('0.00', (string) $row2->paid_principal);
        $this->assertSame('11066.90', $this->loanLedgerBalance($loan));
        $tb = app(PostingService::class)->trialBalance($loan->branch_id);
        $this->assertTrue($tb['balanced']);
    }

    public function test_penalty_bucket_collects_setup_charge_per_overdue_row(): void
    {
        $loan = $this->disbursedLoan(['installment_from_date' => '2026-09-01', 'no_of_installments' => 3]);
        DuePenaltySetup::create(['loan_account_id' => $loan->id, 'penal_charge' => 20, 'updated_by' => $this->admin->id, 'updated_at' => now()]);
        // Rows due Sep 1 + Oct 1 are overdue at the Oct 21 business date.
        $this->assertSame('40.00', $this->money->penaltyDue($loan->fresh()));
        $receipt = $this->money->receive($loan->fresh(), ['amount' => '500', 'penalty_charge' => '40'], $this->admin);
        $this->assertSame('40.00', (string) $receipt->penalty_charge);
        $this->assertSame('460.00', (string) \Brick\Math\BigDecimal::of((string) $receipt->profit_amount)->plus((string) $receipt->investment_amount));
        $this->assertSame('0.00', $this->money->penaltyDue($loan->fresh()));
    }

    public function test_transfer_from_deposit_posts_two_balanced_vouchers(): void
    {
        $loan = $this->disbursedLoan();
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $tx = app(DepositTransactionService::class);
        $account = app(DepositAccountService::class)->open($this->member, $product, [
            'phone' => $this->member->mobile, 'opening_date' => '2026-10-01',
        ], $this->admin->id);
        $tx->receive($account, '5000', null, null, $this->admin);

        $receipt = $this->money->receive($loan, [
            'amount' => '1083.10', 'penalty_charge' => '0',
            'transfer_from_deposit' => true, 'deposit_account_id' => $account->id,
        ], $this->admin);
        $this->assertTrue($receipt->transfer_from_deposit);
        $this->assertNotNull($receipt->deposit_voucher_id);
        $this->assertNotNull($receipt->voucher_id);
        $this->assertSame('3916.90', (string) $account->fresh()->current_balance);
        $this->assertSame('Paid', $loan->schedules()->where('installment_no', 1)->first()->status);
        $tb = app(PostingService::class)->trialBalance($loan->branch_id);
        $this->assertTrue($tb['balanced']);

        // Cancelling reverses BOTH sides.
        $this->money->cancelLatest($receipt, $this->admin, 'Undo transfer');
        $this->assertSame('5000.00', (string) $account->fresh()->current_balance);
        $this->assertSame('Pending', $loan->schedules()->where('installment_no', 1)->first()->status);
    }

    public function test_overpayment_closes_loan_and_refunds_excess(): void
    {
        $loan = $this->disbursedLoan(['loan_amount' => '1200', 'no_of_installments' => 1]);
        // Single installment: principal 1200 + profit 15 = 1215 due.
        $receipt = $this->money->receive($loan, ['amount' => '1500', 'penalty_charge' => '0'], $this->admin);
        $this->assertSame('1200.00', (string) $receipt->investment_amount);
        $this->assertSame('15.00', (string) $receipt->profit_amount);
        $this->assertSame('Closed', $loan->fresh()->status);
        $this->assertSame('0.00', $this->loanLedgerBalance($loan));
        $tb = app(PostingService::class)->trialBalance($loan->branch_id);
        $this->assertTrue($tb['balanced']);
        // The advance payable (2060) must net to zero after the refund.
        $apLedger = \App\Models\Ledger::where('ledger_code', '2060')->firstOrFail();
        $this->assertSame('0.00', app(PostingService::class)->ledgerBalance($apLedger, $loan->branch_id));
    }

    public function test_manual_split_must_add_up(): void
    {
        $loan = $this->disbursedLoan();
        $receipt = $this->money->receive($loan, [
            'amount' => '1000',
            'manual' => ['investment' => '800', 'profit' => '200', 'penalty' => '0'],
        ], $this->admin);
        $this->assertSame('800.00', (string) $receipt->investment_amount);
        // Profit split 200 exceeds row-1 profit due (150) but FIFO profit
        // bucket spans rows — allowed up to total profit outstanding.
        try {
            $this->money->receive($loan->fresh(), [
                'amount' => '1000',
                'manual' => ['investment' => '800', 'profit' => '100', 'penalty' => '0'],
            ], $this->admin);
            $this->fail('Split not adding up should fail.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_receive_history_is_scoped_to_the_selected_loan(): void
    {
        // Two loans for the SAME member with identical terms and one
        // receipt each: loan A's history must list only loan A's
        // receipt — the history query is loan-scoped, not member-scoped.
        $loanA = $this->disbursedLoan();
        $receiptA = $this->money->receive($loanA, ['amount' => '500', 'penalty_charge' => '0'], $this->admin);
        $loanB = $this->disbursedLoan();
        $receiptB = $this->money->receive($loanB, ['amount' => '500', 'penalty_charge' => '0'], $this->admin);
        $this->assertNotSame($loanA->id, $loanB->id);

        $responseA = $this->actingAs($this->admin)
            ->get('/loans/receive/history?member='.$this->member->member_no.'&loan_id='.$loanA->id)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Loans/InstallmentHistory')->has('rows', 1));
        $rowsA = $responseA->viewData('page')['props']['rows'];
        $this->assertSame($receiptA->id, $rowsA[0]['id']);
        $this->assertSame($loanA->loan_number, $rowsA[0]['loan_number']);

        $responseB = $this->actingAs($this->admin)
            ->get('/loans/receive/history?member='.$this->member->member_no.'&loan_id='.$loanB->id)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Loans/InstallmentHistory')->has('rows', 1));
        $rowsB = $responseB->viewData('page')['props']['rows'];
        $this->assertSame($receiptB->id, $rowsB[0]['id']);
        $this->assertSame($loanB->loan_number, $rowsB[0]['loan_number']);
    }

    public function test_pages_render(): void
    {
        $loan = $this->disbursedLoan();
        $this->actingAs($this->admin)->get('/loans/disbursement')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/DisbursementList'));
        $this->actingAs($this->admin)->get('/loans/receive?member='.$this->member->member_no.'&loan_id='.$loan->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/InstallmentReceive')->where('snapshot.status', 'Disbursed'));
        $this->actingAs($this->admin)->get('/loans/receive/cancel?member='.$this->member->member_no.'&loan_id='.$loan->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/ReceiveCancel'));
        $this->actingAs($this->admin)->get('/loans/receive/history?member='.$this->member->member_no.'&loan_id='.$loan->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/InstallmentHistory'));
    }
}
