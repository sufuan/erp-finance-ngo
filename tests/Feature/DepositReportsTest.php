<?php

namespace Tests\Feature;

use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Step 14 — deposit registers return correct rows and actually filter. */
class DepositReportsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private ReportService $reports;
    private DepositAccount $savings;
    private DepositAccount $otherSamityAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->reports = app(ReportService::class);
        $tx = app(DepositTransactionService::class);
        $opener = app(DepositAccountService::class);

        $memberA = Member::query()->firstOrFail();               // Talgachi Samity
        $memberB = Member::query()->skip(2)->firstOrFail();      // Talgachi Mohila Samity
        $savingsProduct = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $this->savings = $opener->open($memberA, $savingsProduct, [
            'phone' => $memberA->mobile, 'opening_date' => '2026-10-05',
        ], $this->admin->id);
        $tx->receive($this->savings, '2000', null, null, $this->admin);
        $tx->payment($this->savings->fresh(), '500', null, null, $this->admin);

        $this->otherSamityAccount = $opener->open($memberB, $savingsProduct, [
            'phone' => $memberB->mobile, 'opening_date' => '2026-10-06',
        ], $this->admin->id);
        $tx->receive($this->otherSamityAccount, '700', null, null, $this->admin);
    }

    public function test_savings_register_and_samity_filter(): void
    {
        $all = $this->reports->run('savings_register', ['account_status' => 'all']);
        $numbers = collect($all['rows'])->map(fn ($r) => $r[1]);
        $this->assertTrue($numbers->contains($this->savings->account_number));
        $this->assertTrue($numbers->contains($this->otherSamityAccount->account_number));
        // Total = 1500 + 700.
        $this->assertSame('2200.00', $all['total_row'][9]);

        $samityA = Samity::where('name', 'Talgachi Samity')->firstOrFail();
        $filtered = $this->reports->run('savings_register', ['account_status' => 'all', 'samity_id' => $samityA->id]);
        $numbers = collect($filtered['rows'])->map(fn ($r) => $r[1]);
        $this->assertTrue($numbers->contains($this->savings->account_number));
        $this->assertFalse($numbers->contains($this->otherSamityAccount->account_number));
    }

    public function test_collection_and_refund_registers(): void
    {
        $collection = $this->reports->run('collection_register', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $this->assertSame('2700.00', $collection['total_row'][6]);
        $refund = $this->reports->run('refund_register', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $this->assertSame('500.00', $refund['total_row'][6]);
        // Out-of-range month is empty.
        $none = $this->reports->run('collection_register', ['from_date' => '2026-09-01', 'to_date' => '2026-09-30']);
        $this->assertCount(0, $none['rows']);
    }

    public function test_monthly_and_zero_transaction_reports(): void
    {
        $monthly = $this->reports->run('monthly_deposit_transaction', ['year' => 2026]);
        $october = collect($monthly['rows'])->firstWhere(0, '10');
        $this->assertSame('2700.00', $october[2]);
        $this->assertSame('500.00', $october[3]);
        $this->assertSame('2200.00', $october[4]);

        // September had no transactions anywhere: both accounts are "zero".
        $zero = $this->reports->run('zero_transaction_report', ['from_date' => '2026-09-01', 'to_date' => '2026-09-30']);
        $numbers = collect($zero['rows'])->map(fn ($r) => $r[1]);
        $this->assertTrue($numbers->contains($this->savings->account_number));
        // October had transactions for both: zero report is empty for them.
        $zeroOct = $this->reports->run('zero_transaction_report', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $numbers = collect($zeroOct['rows'])->map(fn ($r) => $r[1]);
        $this->assertFalse($numbers->contains($this->savings->account_number));
    }

    public function test_dps_target_vs_collection(): void
    {
        $member = Member::query()->firstOrFail();
        $dps = DepositProduct::where('code', 'DPS')->firstOrFail();
        $account = app(DepositAccountService::class)->open($member, $dps, [
            'phone' => $member->mobile, 'opening_date' => '2026-10-01', 'installment_amount' => 500,
        ], $this->admin->id);
        app(DepositTransactionService::class)->receive($account, '500', null, null, $this->admin);
        $result = $this->reports->run('dps_target_vs_collection', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
        $row = collect($result['rows'])->firstWhere(1, $account->account_number);
        $this->assertSame('500.00', $row[5]); // target: 1 month × 500
        $this->assertSame('500.00', $row[6]); // collected
        $this->assertSame('0.00', $row[7]);   // shortfall
    }

    public function test_report_page_and_pdf(): void
    {
        $this->actingAs($this->admin)->get('/reports/deposits?report=collection_register&run=1&from_date=2026-10-01&to_date=2026-10-31')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Reports/DepositReports')->where('rows.0.6', '2000.00'));
        $response = $this->actingAs($this->admin)->get('/reports/deposits/collection_register/pdf?from_date=2026-10-01&to_date=2026-10-31');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
