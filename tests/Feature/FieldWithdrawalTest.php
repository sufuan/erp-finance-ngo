<?php

namespace Tests\Feature;

use App\Models\CollectionBatch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Models\VoucherLine;
use App\Services\CollectionService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Client round 3 (2026-11-18), Part D — field withdrawal: at
 * collection time the worker may pay OUT of the member's general
 * savings beside the deposit, through the same CollectionService
 * batch. LTS (and every non-SAVINGS product) refuses; the
 * compulsory-savings rule is not satisfied by a withdrawal; an
 * over-balance withdrawal rolls the whole batch back.
 */
class FieldWithdrawalTest extends TestCase
{
    use DatabaseTransactions;

    private Samity $samity;

    private User $admin;

    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->samity = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->asAdmin();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->samity->branch_id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeMember(): Member
    {
        $this->memberSeq++;
        $mobile = '0171700000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', [
            'samity_id' => $this->samity->id, 'name' => 'Withdrawal Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199850000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    /** Loan opened + disbursed; the linked savings holds the 10% credit (1,200). */
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

    public function test_collect_loan_withdrawal_posts_payment_and_reduces_balance(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS');
        $this->assertSame('1200.00', (string) $savings->current_balance);
        $due = app(CollectionService::class)->loanReceivable($loan);

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due,
            'savings_amount' => '100', 'savings_withdrawal_amount' => '300',
        ])->assertRedirect('/collection/collect-loan?loan_id='.$loan->id)
            ->assertSessionHas('success', __('collection_posted'));

        $this->assertSame('1000.00', (string) $savings->fresh()->current_balance, '1200 + 100 deposit − 300 withdrawal');

        $payment = DepositTransaction::where('account_id', $savings->id)->where('tran_type', 'Payment')->firstOrFail();
        $this->assertSame('300.00', (string) $payment->amount);

        // The journal is the savings-pay mapping: Dr 2010 / Cr 1010.
        $debit = VoucherLine::where('voucher_id', $payment->voucher_id)
            ->where('ledger_id', Ledger::where('ledger_code', '2010')->value('id'))->firstOrFail();
        $credit = VoucherLine::where('voucher_id', $payment->voucher_id)
            ->where('ledger_id', Ledger::where('ledger_code', '1010')->value('id'))->firstOrFail();
        $this->assertSame('300.00', (string) $debit->debit);
        $this->assertSame('300.00', (string) $credit->credit);

        $batch = CollectionBatch::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame('100.00', (string) $batch->deposit_receive);
        $this->assertSame('300.00', (string) $batch->deposit_payment);
        $line = $batch->lines()->where('direction', 'payment')->firstOrFail();
        $this->assertSame('300.00', (string) $line->amount);
        $this->assertSame($payment->id, $line->ref_id);
    }

    public function test_withdrawal_above_balance_rejects_the_whole_batch(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS');
        $due = app(CollectionService::class)->loanReceivable($loan);

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due,
            'savings_amount' => '100', 'savings_withdrawal_amount' => '99999',
        ])->assertSessionHasErrors('lines');

        $first = LoanSchedule::where('loan_account_id', $loan->id)->where('installment_no', 1)->firstOrFail();
        $this->assertNotSame('Paid', $first->status, 'The installment must roll back with the batch');
        $this->assertSame('1200.00', (string) $savings->fresh()->current_balance);
        $this->assertSame(0, CollectionBatch::where('member_id', $member->id)->count());
        $this->assertSame(0, DepositTransaction::where('account_id', $savings->id)->where('tran_type', 'Payment')->count());
    }

    public function test_lts_withdrawal_line_is_rejected_by_the_engine(): void
    {
        $member = $this->makeMember();
        $this->disbursedLoan($member);
        $product = DepositProduct::where('code', 'LTS')->firstOrFail();
        $this->asAdmin();
        $this->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
            'amount' => 100, 'term_months' => 12,
        ])->assertRedirect('/deposits');
        $lts = $this->account($member, 'LTS');
        $this->assertSame('100.00', (string) $lts->current_balance);

        try {
            app(CollectionService::class)->postBatch($this->admin, [
                'kind' => 'member', 'member_id' => $member->id,
                'lines' => [[
                    'member_id' => $member->id, 'account_type' => 'deposit', 'direction' => 'payment',
                    'account_id' => $lts->id, 'amount' => '50',
                ]],
            ]);
            $this->fail('An LTS withdrawal line must be rejected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(__('field_withdrawal_savings_only'), implode(' ', collect($e->errors())->flatten()->all()));
        }
        $this->assertSame('100.00', (string) $lts->fresh()->current_balance);
    }

    public function test_withdrawal_alone_does_not_satisfy_compulsory_savings(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $due = app(CollectionService::class)->loanReceivable($loan);

        $this->asAdmin();
        $response = $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due, 'savings_withdrawal_amount' => '100',
        ]);
        $response->assertSessionHasErrors('lines');
        $errors = session('errors')->get('lines');
        $this->assertStringContainsString(__('savings_required_with_installment'), implode(' ', $errors));
        $this->assertSame(0, CollectionBatch::where('member_id', $member->id)->count());
    }

    public function test_cancelling_the_batch_reverses_the_withdrawal(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $savings = $this->account($member, 'SAVINGS');

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => '0',
            'savings_amount' => '50', 'savings_withdrawal_amount' => '200',
        ])->assertSessionHas('success', __('collection_posted'));
        $this->assertSame('1050.00', (string) $savings->fresh()->current_balance);

        $batch = CollectionBatch::where('member_id', $member->id)->latest('id')->firstOrFail();
        app(CollectionService::class)->cancelBatch($batch, $this->admin, 'Test reversal');

        $this->assertSame('1200.00', (string) $savings->fresh()->current_balance, 'Both the deposit and the withdrawal reversed');
        $batch = $batch->fresh();
        $this->assertSame('Cancelled', $batch->status);
        $this->assertSame('0.00', (string) $batch->deposit_receive);
        $this->assertSame('0.00', (string) $batch->deposit_payment);
    }

    public function test_dcr_shows_the_field_withdrawal_as_deposit_payment(): void
    {
        $member = $this->makeMember();
        $loan = $this->disbursedLoan($member);
        $due = app(CollectionService::class)->loanReceivable($loan);
        $biz = app(\App\Services\BusinessDayService::class)->currentDate($this->samity->branch_id);
        $runDcr = fn () => app(\App\Services\CollectionReportService::class)->run('dcr_report', [
            '_branch_ids' => [$this->samity->branch_id],
            'samity_id' => $this->samity->id,
            'from_date' => $biz, 'to_date' => $biz,
        ]);
        // Deltas against the pre-collection run: seed rows for this
        // samity may exist, but only THIS post may move the figures.
        $before = collect($runDcr()['rows'])->firstWhere(0, $this->samity->samity_code);

        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due,
            'savings_amount' => '100', 'savings_withdrawal_amount' => '300',
        ])->assertSessionHas('success');

        $dcr = $runDcr();
        $this->assertSame(['samity_code', 'samity', 'deposit_receive', 'loan_receive', 'deposit_payment', 'total_receive'], $dcr['columns']);
        $row = collect($dcr['rows'])->firstWhere(0, $this->samity->samity_code);
        $this->assertNotNull($row);
        $delta = fn (int $i) => (string) \Brick\Math\BigDecimal::of($row[$i])->minus($before[$i] ?? '0')->toScale(2);
        $this->assertSame('100.00', $delta(2), 'deposit receive grew by the collected savings only');
        $this->assertSame($due, $delta(3), 'loan receive grew by the installment');
        $this->assertSame('300.00', $delta(4), 'the field withdrawal shows as deposit payment');
        $this->assertSame(
            (string) \Brick\Math\BigDecimal::of('100.00')->plus($due)->minus('300.00')->toScale(2),
            $delta(5),
            'the DCR total is NET cash: receives + installments − withdrawals'
        );
    }
}
