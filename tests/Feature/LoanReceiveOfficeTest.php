<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanReceive;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\DepositTransactionService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Client requirements 2026-11-18, Feature 6 — lump-sum installment
 * payment and pay-from-savings live ONLY in the Loans module's
 * office Loan Receive path (the field-collection daily cap in
 * postBatch is untouched): a borrower may settle many installments
 * at once, or pay from his own general savings — never from LTS,
 * never from another member's account, never beyond the balance.
 */
class LoanReceiveOfficeTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private User $admin;

    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeMember(): Member
    {
        $this->memberSeq++;
        $mobile = '0171900000'.$this->memberSeq;
        $samity = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Office Receive Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199870000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    /** Daily loan, 50 installments starting in the future (no penalty noise). */
    private function disbursedLoan(Member $member, string $amount = '10000'): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => $amount, 'down_payment' => 0,
            'frequency' => 'D', 'no_of_installments' => 50, 'profit_rate' => null,
            'installment_from_date' => '2027-01-01', 'fees' => [], 'guarantors' => [],
        ], $this->admin);

        return app(LoanMoneyService::class)->disburse($loan, $this->admin);
    }

    private function account(Member $member, string $code): ?DepositAccount
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderBy('id')->first();
    }

    public function test_cash_lump_sum_settles_fifteen_future_installments_at_once(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $installment = (string) $loan->installment_amount;
        $lumpSum = bcmul($installment, '15', 2);
        $money = app(LoanMoneyService::class);
        $before = $money->outstanding($loan)['total'];

        $this->post('/loans/receive', [
            'loan_account_id' => $loan->id, 'amount' => $lumpSum,
        ])->assertRedirect()->assertSessionHas('success');

        $paid = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->orderBy('installment_no')->pluck('installment_no')->all();
        $this->assertSame(range(1, 15), $paid, 'Exactly the first 15 installments flip to Paid');
        $row16 = LoanSchedule::where('loan_account_id', $loan->id)->where('installment_no', 16)->firstOrFail();
        $this->assertNotSame('Paid', $row16->status);
        $after = $money->outstanding($loan->fresh())['total'];
        $this->assertSame($lumpSum, bcsub($before, $after, 2), 'Outstanding drops by exactly the lump sum');
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_receive_from_own_savings_moves_money_and_marks_installments_paid(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS');
        // Disbursement already credited 1,000 (10%); top up via the
        // real deposit receive endpoint so the balance covers 3
        // installments.
        $this->post('/deposits/receive', [
            'account_id' => $savings->id, 'amount' => 5000,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('6000.00', (string) $savings->fresh()->current_balance);

        $installment = (string) $loan->installment_amount;
        $amount = bcmul($installment, '3', 2);
        $this->post('/loans/receive', [
            'loan_account_id' => $loan->id, 'amount' => $amount,
            'transfer_from_deposit' => 1, 'deposit_account_id' => $savings->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(bcsub('6000.00', $amount, 2), (string) $savings->fresh()->current_balance, 'Savings balance drops by the paid amount');
        $paid = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count();
        $this->assertSame(3, $paid);
        $receive = LoanReceive::where('loan_account_id', $loan->id)->latest('id')->firstOrFail();
        $this->assertTrue((bool) $receive->transfer_from_deposit);
        $this->assertNotNull($receive->deposit_voucher_id);
        // The passbook shows the withdrawal.
        $payment = DepositTransaction::where('account_id', $savings->id)
            ->where('tran_type', 'Payment')->whereNull('cancel_at')->latest('id')->first();
        $this->assertNotNull($payment, 'A Payment row appears in the member passbook');
        $this->assertSame($amount, (string) $payment->amount);
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_insufficient_savings_balance_rejects_cleanly(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS'); // balance 1,000 from disbursement

        $this->post('/loans/receive', [
            'loan_account_id' => $loan->id, 'amount' => '5000',
            'transfer_from_deposit' => 1, 'deposit_account_id' => $savings->id,
        ])->assertSessionHasErrors();
        $this->assertSame('1000.00', (string) $savings->fresh()->current_balance, 'Balance untouched');
        $this->assertSame(0, LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count());
        $this->assertSame(0, LoanReceive::where('loan_account_id', $loan->id)->count());
    }

    public function test_another_members_account_cannot_fund_a_receive(): void
    {
        $member = $this->makeMember();
        $other = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        // Give the other member a fat balance (via his own disbursed loan + top-up).
        $this->disbursedLoan($other);
        $othersSavings = $this->account($other, 'SAVINGS');
        app(DepositTransactionService::class)->receive($othersSavings, '5000', null, 'Top up', $this->admin);

        $this->post('/loans/receive', [
            'loan_account_id' => $loan->id, 'amount' => '500',
            'transfer_from_deposit' => 1, 'deposit_account_id' => $othersSavings->id,
        ])->assertSessionHasErrors('deposit_account_id');
        $this->assertSame(0, LoanReceive::where('loan_account_id', $loan->id)->count());
    }

    public function test_lts_account_is_not_a_payment_source(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $ltsProduct = DepositProduct::where('code', 'LTS')->firstOrFail();
        $this->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $ltsProduct->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
            'amount' => 100, 'term_months' => 12,
        ])->assertRedirect('/deposits');
        $lts = $this->account($member, 'LTS');
        app(DepositTransactionService::class)->receive($lts, '5000', null, 'Top up', $this->admin);

        $this->post('/loans/receive', [
            'loan_account_id' => $loan->id, 'amount' => '500',
            'transfer_from_deposit' => 1, 'deposit_account_id' => $lts->id,
        ])->assertSessionHasErrors('deposit_account_id');
        $this->assertSame(0, LoanReceive::where('loan_account_id', $loan->id)->count());

        // …and the receive form's picker does not offer the LTS
        // account at all, while the general savings account is there.
        $this->get('/loans/receive?member='.$member->member_no.'&loan_id='.$loan->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Loans/InstallmentReceive')
                ->where('depositAccounts', function ($accounts) use ($lts) {
                    $this->assertTrue(collect($accounts)->doesntContain('id', $lts->id), 'LTS excluded from the picker');
                    $this->assertNotEmpty($accounts, 'The general savings account is offered');

                    return true;
                })
            );
    }
}
