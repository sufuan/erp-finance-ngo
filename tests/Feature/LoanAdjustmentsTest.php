<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\InsuranceClaim;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanAdjustmentService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Step 19 — reschedule, waiver, penalty pool, write-off + recovery, insurance claims. */
class LoanAdjustmentsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private LoanMoneyService $money;
    private LoanAdjustmentService $adjust;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->money = app(LoanMoneyService::class);
        $this->adjust = app(LoanAdjustmentService::class);
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

    public function test_reschedule_supersedes_old_rows_and_respreads_outstanding(): void
    {
        $loan = $this->disbursedLoan();
        $this->money->receive($loan, ['amount' => '1083.10', 'penalty_charge' => '0'], $this->admin);
        $loan = $loan->fresh();

        $reschedule = $this->adjust->reschedule($loan, [
            'reschedule_date' => '2026-10-21', 'additional_profit' => '100',
            'frequency' => 'M', 'no_of_installments' => 6,
            'first_installment_date' => '2026-12-01', 'fees' => [],
        ], $this->admin);

        $loan = $loan->fresh();
        $active = $loan->schedules()->whereNull('superseded_by')->get();
        $superseded = $loan->schedules()->whereNotNull('superseded_by')->get();
        $this->assertCount(7, $active); // paid row 1 + 6 new rows
        $this->assertCount(11, $superseded);
        $new = $active->where('installment_no', '>', 12);
        $this->assertSame('11066.90', number_format($new->sum(fn ($r) => (float) $r->principal_due), 2, '.', ''));
        $this->assertSame('947.20', number_format($new->sum(fn ($r) => (float) $r->profit_due), 2, '.', ''));
        $this->assertSame(6, $loan->no_of_installments);
        $this->assertSame('1097.20', (string) $loan->profit_amount);
        $this->assertSame('13097.20', (string) $loan->total_repayment);
        $this->assertSame($reschedule->id, $superseded->first()->superseded_by);
        // Outstanding is unchanged by the respread itself.
        $out = $this->money->outstanding($loan);
        $this->assertSame('11066.90', $out['principal']);
        $this->assertSame('947.20', $out['profit']);
    }

    public function test_waiver_reduces_outstanding_and_cancel_restores(): void
    {
        $loan = $this->disbursedLoan();
        $waiver = $this->adjust->waive($loan, [
            'waiver_date' => '2026-10-21', 'principal_waive' => '1000',
            'profit_waive' => '200', 'charge_waive' => '0', 'comments' => null,
        ], $this->admin);
        $out = $this->money->outstanding($loan->fresh());
        $this->assertSame('11000.00', $out['principal']);
        $this->assertSame('797.20', $out['profit']);
        $this->assertSame('1200.00', (string) $waiver->total_waiver);

        $this->adjust->cancelWaiver($waiver, $this->admin);
        $out = $this->money->outstanding($loan->fresh());
        $this->assertSame('12000.00', $out['principal']);
        $this->assertSame('997.20', $out['profit']);
    }

    public function test_penalty_pool_collects_and_cancel_removes_additional_profit(): void
    {
        $loan = $this->disbursedLoan();
        $penalty = $this->adjust->addPenalty($loan, [
            'entry_date' => '2026-10-21', 'additional_profit' => '30',
            'penalty_amount' => '50', 'comments' => null,
        ], $this->admin);
        $this->assertSame('50.00', $this->money->penaltyDue($loan->fresh()));
        $this->assertSame('1027.20', $this->money->outstanding($loan->fresh())['profit']);

        $receipt = $this->money->receive($loan->fresh(), ['amount' => '50', 'penalty_charge' => null], $this->admin);
        $this->assertSame('50.00', (string) $receipt->penalty_charge);
        $this->assertSame('0.00', $this->money->penaltyDue($loan->fresh()));

        $this->adjust->cancelPenalty($penalty, $this->admin);
        $this->assertSame('997.20', $this->money->outstanding($loan->fresh())['profit']);
        // Collected pool penalty stays collected (cancel does not refund cash).
        $this->assertSame('0.00', $this->money->penaltyDue($loan->fresh()));
    }

    public function test_writeoff_then_recovery(): void
    {
        $loan = $this->disbursedLoan();
        $writeoff = $this->adjust->writeoff($loan, [
            'writeoff_date' => '2026-10-21', 'comments' => 'Defaulted',
        ], $this->admin);
        $this->assertSame('WrittenOff', $loan->fresh()->status);
        $this->assertSame('12000.00', (string) $writeoff->principal);
        $this->assertSame('997.20', (string) $writeoff->profit);
        $this->assertSame('0.00', $this->loanLedgerBalance($loan));
        $this->assertTrue(app(PostingService::class)->trialBalance($loan->branch_id)['balanced']);

        $collection = $this->adjust->collectWriteoff($loan->fresh(), [
            'recovery_date' => '2026-10-21', 'principal_recovery' => '5000',
            'profit_recovery' => '500', 'charge_recovery' => '0', 'comments' => null,
        ], $this->admin);
        $this->assertSame('5500.00', (string) $collection->total_recovery);
        $this->assertSame('5000.00', $this->loanLedgerBalance($loan));
        $this->assertTrue(app(PostingService::class)->trialBalance($loan->branch_id)['balanced']);
    }

    public function test_insurance_claim_pay_settles_loan_installment(): void
    {
        $loan = $this->disbursedLoan();
        $claim = InsuranceClaim::create([
            'member_id' => $this->member->id, 'loan_account_id' => $loan->id,
            'claim_type' => 'death', 'claim_date' => '2026-10-21',
            'amount' => '1083.10', 'documents' => [], 'status' => 'Filed',
        ]);
        $this->adjust->decideClaim($claim, 'approve', $this->admin);
        $this->assertSame('Approved', $claim->fresh()->status);
        $this->adjust->payClaim($claim->fresh(), $this->admin);
        $this->assertSame('Paid', $claim->fresh()->status);
        $this->assertSame('Paid', $loan->schedules()->where('installment_no', 1)->first()->status);
        $this->assertSame('Active', $loan->fresh()->status);
    }

    public function test_pages_render(): void
    {
        $loan = $this->disbursedLoan();
        foreach ([
            '/loans/reschedule?loan_id='.$loan->id => 'Loans/Reschedule',
            '/loans/waiver?loan_id='.$loan->id => 'Loans/Waiver',
            '/loans/penalty?loan_id='.$loan->id => 'Loans/AdditionalPenalty',
            '/loans/writeoff?loan_id='.$loan->id => 'Loans/WriteOff',
            '/loans/insurance-claims' => 'Loans/InsuranceClaims',
        ] as $url => $component) {
            $this->actingAs($this->admin)->get($url)
                ->assertOk()->assertInertia(fn ($p) => $p->component($component));
        }
    }
}
