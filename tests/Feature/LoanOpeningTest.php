<?php

namespace Tests\Feature;

use App\Models\DepositLien;
use App\Models\DepositProduct;
use App\Models\DuePenaltySetup;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\LoanOpeningService;
use App\Services\LoanProposalService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Step 17 — loan opening: golden schedules, calendar shifting, fees, liens. */
class LoanOpeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private LoanOpeningService $opening;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->opening = app(LoanOpeningService::class);
        $this->member = Member::query()->firstOrFail();
    }

    private function open(array $overrides = []): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();

        return $this->opening->open(array_merge([
            'member_id' => $this->member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01',
            'fees' => [], 'guarantors' => [],
        ], $overrides), $this->admin);
    }

    public function test_declining_schedule_amortizes_and_sums_exactly(): void
    {
        $loan = $this->open();
        $this->assertSame('Opened', $loan->status);
        $this->assertNotEmpty($loan->loan_number);
        $rows = $loan->schedules()->orderBy('installment_no')->get();
        $this->assertCount(12, $rows);
        $this->assertSame('12000.00', number_format($rows->sum(fn ($r) => (float) $r->principal_due), 2, '.', ''));
        $this->assertEqualsWithDelta((float) $loan->total_repayment, (float) $rows->sum(fn ($r) => $r->total_due), 0.001);
        // First row: profit = 12000 × 1.25% = 150.00; principal = 1083.10 − 150.
        $this->assertSame('150.00', (string) $rows[0]->profit_due);
        $this->assertSame('933.10', (string) $rows[0]->principal_due);
        // Amortization shape: profit shrinks, principal grows.
        $this->assertTrue((float) $rows[11]->profit_due < (float) $rows[0]->profit_due);
        $this->assertTrue((float) $rows[11]->principal_due > (float) $rows[0]->principal_due);
        $this->assertSame($rows[11]->due_date->toDateString(), $loan->installment_expire_date->toDateString());
    }

    public function test_flat_weekly_schedule_equal_splits(): void
    {
        $flat = LoanProduct::create([
            'code' => '3902', 'name_en' => 'Flat Weekly', 'profit_rate' => 10, 'rate_method' => 'flat',
            'min_amount' => 1000, 'max_amount' => 500000, 'min_term' => 1, 'max_term' => 100,
            'allowed_frequencies' => ['W'], 'gl_mapping_prefix' => 'loan.general', 'is_active' => true,
        ]);
        $loan = $this->open([
            'loan_product_id' => $flat->id, 'loan_amount' => '5200',
            'frequency' => 'W', 'no_of_installments' => 52, 'installment_from_date' => '2026-10-28',
        ]);
        $rows = $loan->schedules()->orderBy('installment_no')->get();
        $this->assertCount(52, $rows);
        $this->assertSame('520.00', (string) $loan->profit_amount);
        $this->assertSame('100.00', (string) $rows[0]->principal_due);
        $this->assertSame('10.00', (string) $rows[0]->profit_due);
        $this->assertSame('5200.00', number_format($rows->sum(fn ($r) => (float) $r->principal_due), 2, '.', ''));
    }

    public function test_due_dates_shift_off_weekend_and_holidays(): void
    {
        // First due Friday 2026-10-23 → weekend Fri/Sat → Sunday 2026-10-25.
        $loan = $this->open(['installment_from_date' => '2026-10-23']);
        $this->assertSame('2026-10-25', $loan->schedules()->orderBy('installment_no')->first()->due_date->toDateString());

        // With a holiday on that Sunday, it moves to Monday 2026-10-26.
        Holiday::create([
            'branch_id' => $this->member->branch_id, 'samity_id' => null,
            'holiday_date' => '2026-10-25', 'details' => 'Test holiday', 'created_by' => $this->admin->id,
        ]);
        $loan2 = $this->open(['installment_from_date' => '2026-10-23']);
        $this->assertSame('2026-10-26', $loan2->schedules()->orderBy('installment_no')->first()->due_date->toDateString());
    }

    public function test_fees_guarantors_and_proposal_lien_fill(): void
    {
        // Build an approved proposal carrying an FDR lien.
        $fdrProduct = DepositProduct::where('code', 'FDR')->firstOrFail();
        $tx = app(DepositTransactionService::class);
        $fdr = app(DepositAccountService::class)->open($this->member, $fdrProduct, [
            'phone' => $this->member->mobile, 'opening_date' => '2026-10-01',
        ], $this->admin->id);
        $tx->receive($fdr, '50000', null, null, $this->admin);
        $proposalService = app(LoanProposalService::class);
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $proposal = $proposalService->submit($this->member, [
            'loan_product_id' => $product->id, 'proposal_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000',
            'fdr_account_id' => $fdr->id, 'frequency' => 'M', 'no_of_installments' => 12,
            'profit_rate' => null, 'installment_from_date' => '2026-11-01', 'guarantors' => null,
        ], $this->admin);
        $proposalService->approve($proposal, $this->admin);

        $loan = $this->open([
            'loan_proposal_id' => $proposal->id,
            'fees' => ['form' => 100, 'risk' => 50],
            'guarantors' => [['name' => 'Karim Uddin', 'relation' => 'Brother', 'phone' => '01710000000', 'address' => 'Talgachi', 'national_id' => '1234567890']],
        ]);
        $this->assertSame($proposal->id, $loan->loan_proposal_id);
        $lien = DepositLien::where('loan_proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame($loan->id, $lien->loan_account_id);

        $fees = $loan->fees()->orderBy('id')->get();
        $this->assertCount(2, $fees);
        $this->assertSame('form', $fees[0]->fee_type);
        $this->assertSame('4030', $fees[0]->ledger->ledger_code);
        $this->assertSame('4032', $fees[1]->ledger->ledger_code);
        $this->assertNotNull($fees[0]->voucher_id);
        $tb = app(PostingService::class)->trialBalance($loan->branch_id);
        $this->assertTrue($tb['balanced']);

        $guarantor = $loan->guarantors()->firstOrFail();
        $this->assertSame('Karim Uddin', $guarantor->name);
        $this->assertSame('1234567890', $guarantor->national_id); // decrypted via cast
        $raw = DB::table('guarantors')->where('id', $guarantor->id)->value('national_id');
        $this->assertNotSame('1234567890', $raw); // stored encrypted
        $this->assertSame(hash('sha256', '1234567890'), $guarantor->national_id_hash);
    }

    public function test_opening_rate_cap_blocks_override_and_flat_effective(): void
    {
        // Override to 25% on the declining product → cap breach.
        try {
            $this->open(['profit_rate' => '25']);
            $this->fail('25% opening should be blocked.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        // Flat product at 12% opens; overriding it to 15% flat (≈26.6% eff.) blocks.
        $flat = LoanProduct::create([
            'code' => '3903', 'name_en' => 'Flat Twelve', 'profit_rate' => 12, 'rate_method' => 'flat',
            'min_amount' => 1000, 'max_amount' => 500000, 'min_term' => 1, 'max_term' => 100,
            'allowed_frequencies' => ['M'], 'gl_mapping_prefix' => 'loan.general', 'is_active' => true,
        ]);
        $loan = $this->open(['loan_product_id' => $flat->id]);
        $this->assertSame('Opened', $loan->status);
        try {
            $this->open(['loan_product_id' => $flat->id, 'profit_rate' => '15']);
            $this->fail('15% flat opening should be blocked.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_staff_opening_and_penalty_setup_and_pages(): void
    {
        $employee = Employee::query()->firstOrFail();
        $loan = $this->open(['member_id' => null, 'employee_id' => $employee->id]);
        $this->assertSame($employee->id, $loan->employee_id);
        $this->assertNull($loan->member_id);

        $this->actingAs($this->admin)->post('/loans/due-penalty/'.$loan->id, ['penal_charge' => 25])
            ->assertRedirect();
        $setup = DuePenaltySetup::where('loan_account_id', $loan->id)->firstOrFail();
        $this->assertSame('25.00', (string) $setup->penal_charge);

        $this->actingAs($this->admin)->get('/loans/opening?member='.$this->member->member_no)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/Opening')->where('mode', 'member'));
        $this->actingAs($this->admin)->get('/loans/opening?mode=product')
            ->assertOk()->assertInertia(fn ($p) => $p->where('mode', 'product'));
        $this->actingAs($this->admin)->get('/loans/due-penalty')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/DuePenaltySetup'));
    }
}
