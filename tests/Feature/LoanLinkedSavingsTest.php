<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashLedger;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\LoanFee;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Models\Voucher;
use App\Services\BusinessDayService;
use App\Services\LoanOpeningService;
use App\Services\PostingService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Client requirements 2026-11-18, Feature 1 — loan opening /
 * disbursement: the linked general savings account opens
 * automatically with the loan, the insurance fee (1%) posts to its
 * own income ledger (4036), custom named fees post to Others (4035),
 * and at disbursement 10% of the loan is credited to the savings
 * account while the member takes the rest in cash — one balanced
 * voucher, no double-counted cash.
 */
class LoanLinkedSavingsTest extends TestCase
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
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeMember(): Member
    {
        $this->memberSeq++;
        $mobile = '0171500000'.$this->memberSeq;
        $this->post('/members', [
            'samity_id' => $this->samity->id, 'name' => 'Linked Savings Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199830000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    /** Open a loan through the real opening endpoint. */
    private function openLoan(Member $member, array $overrides = []): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $this->post('/loans/opening', array_merge([
            'member_id' => $member->id, 'loan_product_id' => $product->id,
            'opening_date' => '2026-10-21', 'loan_purpose' => 'Agriculture',
            'loan_amount' => '10000', 'frequency' => 'M', 'no_of_installments' => 12,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'custom_fees' => [],
        ], $overrides))->assertRedirect()->assertSessionHas('success');

        return LoanAccount::where('member_id', $member->id)->latest('id')->firstOrFail();
    }

    private function savingsAccount(Member $member): ?DepositAccount
    {
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderBy('id')->first();
    }

    public function test_opening_auto_creates_the_linked_savings_account_once(): void
    {
        $member = $this->makeMember();
        $this->assertNull($this->savingsAccount($member));

        $this->openLoan($member);
        $account = $this->savingsAccount($member);
        $this->assertNotNull($account, 'Loan opening must auto-create the linked savings account');
        $this->assertTrue($account->is_active);
        $this->assertNull($account->closing_date);
        $this->assertSame('0.00', (string) $account->current_balance);
        $businessDate = app(BusinessDayService::class)->currentDate($this->branch->id);
        $this->assertSame($businessDate, $account->opening_date->toDateString(), 'Auto account opens on the business date');

        // A second loan reuses the same account — never a duplicate.
        $this->openLoan($member, ['loan_amount' => '5000']);
        $this->assertSame(1, DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $account->deposit_product_id)->count());
    }

    public function test_opening_reuses_a_pre_existing_savings_account(): void
    {
        $member = $this->makeMember();
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $this->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21', 'amount' => 200,
        ])->assertRedirect('/deposits');
        $existing = $this->savingsAccount($member);
        $this->assertSame('200.00', (string) $existing->current_balance);

        $this->openLoan($member);
        $this->assertSame($existing->id, $this->savingsAccount($member)->id);
        $this->assertSame('200.00', (string) $this->savingsAccount($member)->fresh()->current_balance);
    }

    public function test_insurance_and_custom_fees_post_to_their_own_ledgers(): void
    {
        $member = $this->makeMember();
        $loan = $this->openLoan($member, [
            'fees' => ['form' => 50, 'insurance' => 100],
            'custom_fees' => [
                ['name' => 'Stationery charge', 'amount' => 30],
                ['name' => '', 'amount' => 0],
            ],
        ]);

        $fees = LoanFee::where('loan_account_id', $loan->id)->orderBy('id')->get();
        $byType = $fees->keyBy('fee_type');
        $this->assertSame('100.00', (string) $byType['insurance']->amount);
        $this->assertSame(Ledger::byCode('4036')->id, $byType['insurance']->ledger_id, 'Insurance posts to its own ledger 4036');
        $this->assertSame('50.00', (string) $byType['form']->amount);
        $this->assertSame(Ledger::byCode('4030')->id, $byType['form']->ledger_id);
        $this->assertSame('Stationery charge', $byType['custom']->label, 'Custom fee keeps the typed name');
        $this->assertSame('30.00', (string) $byType['custom']->amount);
        $this->assertSame(Ledger::byCode('4035')->id, $byType['custom']->ledger_id, 'Custom fees post to Others 4035');

        $voucher = Voucher::findOrFail($byType['insurance']->voucher_id);
        $credits = $voucher->lines()->selectRaw('ledger_id, SUM(credit) as c')->groupBy('ledger_id')->pluck('c', 'ledger_id');
        $this->assertSame('100.00', (string) $credits[Ledger::byCode('4036')->id]);
        $this->assertSame('30.00', (string) $credits[Ledger::byCode('4035')->id]);
        $this->assertSame('50.00', (string) $credits[Ledger::byCode('4030')->id]);
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_custom_fee_with_amount_but_no_name_is_rejected(): void
    {
        $member = $this->makeMember();
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $this->post('/loans/opening', [
            'member_id' => $member->id, 'loan_product_id' => $product->id,
            'opening_date' => '2026-10-21', 'loan_amount' => '10000',
            'frequency' => 'M', 'no_of_installments' => 12, 'installment_from_date' => '2026-11-01',
            'fees' => [], 'custom_fees' => [['name' => '', 'amount' => 25]],
        ])->assertSessionHasErrors('custom_fees');
        $this->assertSame(0, LoanAccount::where('member_id', $member->id)->count(), 'Nothing is opened when validation fails');
    }

    private function cashOfAdmin(): string
    {
        return (string) (CashLedger::where('user_id', $this->admin->id)->value('current_balance') ?? '0.00');
    }

    public function test_disbursement_credits_ten_percent_to_savings_and_rest_in_cash(): void
    {
        $member = $this->makeMember();
        $loan = $this->openLoan($member); // 10,000
        $account = $this->savingsAccount($member);
        $cashBefore = $this->cashOfAdmin();

        $this->post('/loans/disbursement/'.$loan->id)->assertRedirect()->assertSessionHas('success');

        $loan = $loan->fresh();
        $this->assertSame('Disbursed', $loan->status);
        $this->assertSame('1000.00', (string) $loan->savings_deposit_amount, '10% of 10,000 recorded on the loan');
        $this->assertSame($account->id, $loan->savings_account_id);
        $this->assertSame('1000.00', (string) $account->fresh()->current_balance, 'Savings balance is the 10% credit');

        // The passbook row: a real Receive on the disbursement voucher.
        $detail = DepositTransaction::where('account_id', $account->id)
            ->where('tran_type', 'Receive')->whereNull('cancel_at')->firstOrFail();
        $this->assertSame('1000.00', (string) $detail->amount);
        $this->assertStringContainsString('Loan-linked savings deposit', (string) $detail->comments);
        $voucher = Voucher::findOrFail($detail->voucher_id);
        $this->assertSame('Journal', $voucher->voucher_type);

        // One voucher carries the split: Dr portfolio 10,000;
        // Cr cash 9,000; Cr savings liability (2010) 1,000.
        $debit = $voucher->lines()->where('ledger_id', Ledger::byCode('1100')->id)->sum('debit');
        $cashCredit = $voucher->lines()->where('ledger_id', Ledger::byCode('1010')->id)->sum('credit');
        $savingsCredit = $voucher->lines()->where('ledger_id', Ledger::byCode('2010')->id)->sum('credit');
        $this->assertSame('10000.00', (string) $debit);
        $this->assertSame('9000.00', (string) $cashCredit, 'Cash is credited only the net amount — never double-counted');
        $this->assertSame('1000.00', (string) $savingsCredit);

        // The officer's cash-in-hand drops by the NET cash only.
        $this->assertSame(bcsub($cashBefore, '9000', 2), $this->cashOfAdmin(), 'Cash drops by the net 9,000 only');
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_disbursement_with_zero_savings_rate_behaves_as_before(): void
    {
        app(SettingsService::class)->set('loan.savings_deposit_rate', 0, $this->admin->id);
        $member = $this->makeMember();
        $loan = $this->openLoan($member);
        $cashBefore = $this->cashOfAdmin();
        $this->post('/loans/disbursement/'.$loan->id)->assertRedirect()->assertSessionHas('success');

        $account = $this->savingsAccount($member);
        $this->assertSame('0.00', (string) $account->fresh()->current_balance);
        $this->assertSame(0, DepositTransaction::where('account_id', $account->id)->count());
        $this->assertSame(bcsub($cashBefore, '10000', 2), $this->cashOfAdmin(), 'Full amount leaves cash when the rate is 0');
        $this->assertSame('0.00', (string) $loan->fresh()->savings_deposit_amount);
    }

    public function test_cancelling_the_disbursement_voucher_reverses_the_savings_credit(): void
    {
        $member = $this->makeMember();
        $loan = $this->openLoan($member);
        $cashBefore = $this->cashOfAdmin();
        $this->post('/loans/disbursement/'.$loan->id)->assertRedirect();
        $account = $this->savingsAccount($member);
        $detail = DepositTransaction::where('account_id', $account->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $account->fresh()->current_balance);

        app(PostingService::class)->cancel(Voucher::findOrFail($detail->voucher_id), $this->admin, 'Wrong disbursement');

        $this->assertSame('0.00', (string) $account->fresh()->current_balance, 'Savings credit is reversed with the voucher');
        $this->assertNotNull($detail->fresh()->cancel_at, 'Passbook row is flagged cancelled');
        $this->assertSame($cashBefore, $this->cashOfAdmin(), 'Cash returns to the pre-disbursement figure');
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_opening_service_level_still_auto_creates_for_direct_callers(): void
    {
        // Other flows (tests, imports) open loans through the service
        // directly — the auto-account rule lives in the service.
        $member = $this->makeMember();
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '8000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $this->admin);
        $this->assertNotNull($this->savingsAccount($member));
    }
}
