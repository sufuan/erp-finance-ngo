<?php

namespace Tests\Feature;

use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanOpeningService;
use App\Services\ScheduleEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the defects found by the full live CRUD pass
 * (2026-10-27): deposit opening amount handling, loan opening with
 * blank payment fields, and calculator/schedule total parity.
 */
class LiveCrudFixesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    // ---- Defect 3: deposit opening amount ----

    public function test_deposit_opening_with_blank_amount_succeeds_at_zero(): void
    {
        $member = Member::query()->firstOrFail();
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();

        // The real form payload with Amount left blank.
        $this->actingAs($this->admin)->post('/deposits', [
            'member_id' => $member->id,
            'deposit_product_id' => $product->id,
            'phone' => $member->mobile,
            'opening_date' => '2026-10-21',
            'amount' => '',
        ])->assertRedirect('/deposits')->assertSessionHasNoErrors();

        $account = DepositAccount::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame('0.00', (string) $account->current_balance);
        $this->assertSame(0, DepositTransaction::where('account_id', $account->id)->count());
    }

    public function test_deposit_opening_amount_is_credited_as_opening_receive(): void
    {
        $member = Member::query()->firstOrFail();
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();

        $this->actingAs($this->admin)->post('/deposits', [
            'member_id' => $member->id,
            'deposit_product_id' => $product->id,
            'phone' => $member->mobile,
            'opening_date' => '2026-10-21',
            'amount' => 100,
        ])->assertRedirect('/deposits')->assertSessionHasNoErrors();

        $account = DepositAccount::where('member_id', $member->id)->latest('id')->firstOrFail();
        // Amount still records the installment size AND is real money now.
        $this->assertSame('100.00', (string) $account->installment_amount);
        $this->assertSame('100.00', (string) $account->current_balance);
        $this->assertSame('100.00', (string) $account->total_credit);
        $txn = DepositTransaction::where('account_id', $account->id)->firstOrFail();
        $this->assertSame('Receive', $txn->tran_type);
        $this->assertSame('100.00', (string) $txn->amount);
    }

    // ---- Defect 4: loan opening with blank payment fields ----

    public function test_loan_opening_with_blank_payment_fields_creates_loan(): void
    {
        $member = Member::query()->firstOrFail();
        $product = LoanProduct::where('code', '3001')->firstOrFail();

        // Exactly what the Loan Opening form posts when Payment Type and
        // Payment To Account are left blank (they are optional).
        $this->actingAs($this->admin)->post('/loans/opening', [
            'member_id' => $member->id,
            'employee_id' => '',
            'loan_proposal_id' => '',
            'loan_product_id' => $product->id,
            'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture',
            'loan_amount' => '12000',
            'down_payment' => 0,
            'frequency' => 'M',
            'no_of_installments' => 12,
            'profit_rate' => '',
            'installment_from_date' => '2026-11-01',
            'payment_type' => '',
            'payment_to_account' => '',
            'fees' => [],
            'guarantors' => [],
        ])->assertRedirect('/loans/opening?mode=member')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('loan_accounts', [
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'status' => 'Opened',
        ]);
    }

    // ---- Defect 6: calculator must match the generated schedule ----

    public function test_preview_matches_generated_schedule_for_live_scenario(): void
    {
        $engine = app(ScheduleEngine::class);
        // The exact live case: 10,000 @ 15% declining, weekly ×12 from
        // Monday 2026-10-26; the member's samity (0001) meets Wednesdays.
        // 849.04 × 12 = 10188.48 is NOT the schedule sum — per-period
        // profit rounding makes the real schedule total 10188.51, and
        // samity-day alignment moves the last due date to 2027-01-13.
        $rows = $engine->rows('10000', '15', 'declining', 'W', 12, '2026-10-26', 2, 1, 'Wednesday');
        $preview = $engine->preview('10000', '15', 'declining', 'W', 12, '2026-10-26', 2, 1, 'Wednesday');

        $sum = number_format(collect($rows)->sum(fn ($r) => (float) $r['total_due']), 2, '.', '');
        $this->assertSame('10188.51', $sum);
        $this->assertSame($sum, $preview['total_repayment']);
        $this->assertSame('188.51', $preview['profit_amount']);
        $this->assertSame('849.04', $preview['installment_amount']);
        $this->assertSame(end($rows)['total_due'], $preview['last_installment_amount']);
        $this->assertSame(end($rows)['due_date'], $preview['installment_expire_date']);
        $this->assertSame('2027-01-13', $preview['installment_expire_date']);
    }

    public function test_opened_loan_totals_equal_preview_and_schedule_sums(): void
    {
        $member = Member::query()->firstOrFail(); // samity 0001 — Wednesdays
        $product = LoanProduct::where('code', '3001')->firstOrFail();

        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-26',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '10000', 'down_payment' => 0,
            'frequency' => 'W', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-10-26',
            'payment_type' => null, 'payment_to_account' => null,
            'fees' => [], 'guarantors' => [],
        ], $this->admin);

        $rows = $loan->schedules()->orderBy('installment_no')->get();
        $sum = number_format($rows->sum(fn ($r) => (float) $r->total_due), 2, '.', '');
        $this->assertSame('10188.51', $sum);
        $this->assertSame($sum, (string) $loan->total_repayment);
        $this->assertSame('2027-01-13', $loan->installment_expire_date->toDateString());
        $this->assertSame($rows->last()->due_date->toDateString(), $loan->installment_expire_date->toDateString());

        // The calculator endpoint, given the member, agrees with both.
        $response = $this->actingAs($this->admin)->postJson('/loans/proposals/preview', [
            'loan_product_id' => $product->id, 'loan_amount' => 10000,
            'frequency' => 'W', 'no_of_installments' => 12,
            'installment_from_date' => '2026-10-26', 'member_id' => $member->id,
        ])->assertOk();
        $this->assertSame('10188.51', $response->json('total_repayment'));
        $this->assertSame('2027-01-13', $response->json('installment_expire_date'));
    }
}
