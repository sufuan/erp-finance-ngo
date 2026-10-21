<?php

namespace Tests\Feature;

use App\Models\DepositAccount;
use App\Models\DepositLien;
use App\Models\DepositProduct;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\LoanProposalService;
use App\Services\ScheduleEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Step 16 — proposals: schedule preview, eligibility, approval + FDR lien. */
class LoanProposalsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private LoanProposalService $service;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->service = app(LoanProposalService::class);
        $this->member = Member::query()->firstOrFail();
    }

    private function submit(array $overrides = []): \App\Models\LoanProposal
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();

        return $this->service->submit($this->member, array_merge([
            'loan_product_id' => $product->id, 'proposal_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000',
            'fdr_account_id' => null, 'frequency' => 'M', 'no_of_installments' => 12,
            'profit_rate' => null, 'installment_from_date' => '2026-11-01',
            'guarantors' => [['name' => 'Karim', 'relation' => 'Brother', 'phone' => '01710000000', 'address' => 'Talgachi', 'national_id' => '1234567890']],
        ], $overrides), $this->admin);
    }

    public function test_schedule_preview_math_declining_and_flat(): void
    {
        $engine = app(ScheduleEngine::class);
        // Declining 15%, 12,000, monthly ×12 → PMT ≈ 1083.10, profit ≈ 997.20.
        $preview = $engine->preview('12000', '15', 'declining', 'M', 12, '2026-11-01');
        $this->assertEqualsWithDelta(1083.10, (float) $preview['installment_amount'], 0.02);
        $this->assertEqualsWithDelta(997.20, (float) $preview['profit_amount'], 0.25);
        $this->assertSame('2027-10-01', $preview['installment_expire_date']);
        $this->assertEqualsWithDelta(
            (float) $preview['total_repayment'],
            (float) $preview['installment_amount'] * 12,
            0.02,
        );

        // Flat 12%, 10,000, monthly ×12 → profit exactly 1200; last absorbs rounding.
        $flat = $engine->preview('10000', '12', 'flat', 'M', 12, '2026-11-01');
        $this->assertSame('1200.00', $flat['profit_amount']);
        $this->assertSame('11200.00', $flat['total_repayment']);
        $this->assertSame('933.33', $flat['installment_amount']);
        $this->assertSame('933.37', $flat['last_installment_amount']);

        // Weekly flat: term = 52/52 = 1 year → profit = 10% of 5200.
        $weekly = $engine->preview('5200', '10', 'flat', 'W', 52, '2026-11-01');
        $this->assertSame('520.00', $weekly['profit_amount']);
    }

    public function test_submit_approve_flow_and_list(): void
    {
        $proposal = $this->submit();
        $this->assertSame('Submitted', $proposal->status);
        $this->assertSame('15.00', (string) $proposal->profit_rate);
        $this->assertEqualsWithDelta(1083.10, (float) $proposal->installment_amount, 0.02);

        $this->actingAs($this->admin)->get('/loans/proposals?status=Submitted')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/ProposalList')->has('proposals', 1));

        $approved = $this->service->approve($proposal, $this->admin);
        $this->assertSame('Approved', $approved->status);
        $this->assertNotNull($approved->decided_at);
        // Double decision is refused.
        try {
            $this->service->approve($approved, $this->admin);
            $this->fail('Second approval should fail.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_concurrent_loan_eligibility_blocks_third_proposal(): void
    {
        $product = LoanProduct::create([
            'code' => '3901', 'name_en' => 'Two Max', 'profit_rate' => 10, 'rate_method' => 'declining',
            'min_amount' => 1000, 'max_amount' => 500000, 'min_term' => 1, 'max_term' => 100,
            'allowed_frequencies' => ['M'], 'gl_mapping_prefix' => 'loan.general',
            'max_concurrent_loans' => 2, 'is_active' => true,
        ]);
        foreach ([1, 2] as $i) {
            $p = $this->submit(['loan_product_id' => $product->id, 'profit_rate' => '10']);
            $this->service->approve($p, $this->admin);
        }
        app()->setLocale('en');
        try {
            $this->submit(['loan_product_id' => $product->id, 'profit_rate' => '10']);
            $this->fail('Third proposal should be blocked by eligibility.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
            $this->assertStringContainsString('maximum number of active loans', collect($e->errors())->flatten()->implode(' '));
        }
    }

    public function test_fdr_lien_created_on_approval_and_blocks_withdrawal(): void
    {
        $fdrProduct = DepositProduct::where('code', 'FDR')->firstOrFail();
        $tx = app(DepositTransactionService::class);
        $fdr = app(DepositAccountService::class)->open($this->member, $fdrProduct, [
            'phone' => $this->member->mobile, 'opening_date' => '2026-10-01',
        ], $this->admin->id);
        $tx->receive($fdr, '50000', null, null, $this->admin);

        $proposal = $this->submit(['fdr_account_id' => $fdr->id]);
        $this->service->approve($proposal, $this->admin);

        $lien = DepositLien::where('loan_proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame('Active', $lien->status);
        $this->assertSame($fdr->id, $lien->deposit_account_id);
        $this->assertNull($lien->loan_account_id);
        $this->assertSame('12000.00', (string) $lien->amount);

        // Withdrawal that would breach the lien is blocked; down to the lien is fine.
        try {
            $tx->payment($fdr->fresh(), '38001', null, null, $this->admin);
            $this->fail('Payment breaching the lien should fail.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $tx->payment($fdr->fresh(), '38000', null, null, $this->admin);
        $this->assertSame('12000.00', (string) $fdr->fresh()->current_balance);
    }

    public function test_pages_render(): void
    {
        $this->actingAs($this->admin)->get('/loans/proposals/create?member='.$this->member->member_no)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/ProposalCreate')->has('products', 3));
        $this->actingAs($this->admin)->get('/loans/proposals')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/ProposalList'));
        $response = $this->actingAs($this->admin)->postJson('/loans/proposals/preview', [
            'loan_product_id' => LoanProduct::where('code', '3001')->value('id'),
            'loan_amount' => 12000, 'frequency' => 'M', 'no_of_installments' => 12,
            'installment_from_date' => '2026-11-01',
        ]);
        $response->assertOk();
        $this->assertEqualsWithDelta(1083.10, (float) $response->json('installment_amount'), 0.02);
    }
}
