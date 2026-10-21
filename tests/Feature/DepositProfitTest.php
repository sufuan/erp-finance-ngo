<?php

namespace Tests\Feature;

use App\Models\DailyBalance;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\ProfitSetup;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\PostingService;
use App\Services\ProfitDistributionService;
use App\Services\ProfitEngine;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 13 acceptance — hand-checked profit math:
 * 10,000 average balance, 15%, Oct 1–31 (31 days) → 10000×15×31/36500 = 127.40.
 */
class DepositProfitTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private ProfitDistributionService $service;
    private ProfitEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->service = app(ProfitDistributionService::class);
        $this->engine = app(ProfitEngine::class);
    }

    private function accountWithSnapshots(string $balance = '10000', int $days = 31): DepositAccount
    {
        $member = Member::query()->firstOrFail();
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $account = app(DepositAccountService::class)->open($member, $product, [
            'phone' => $member->mobile, 'opening_date' => '2026-10-01',
        ], $this->admin->id);
        app(DepositTransactionService::class)->receive($account, '50000', null, null, $this->admin);
        for ($d = 1; $d <= $days; $d++) {
            DailyBalance::create([
                'account_type' => 'deposit', 'account_id' => $account->id,
                'balance_date' => sprintf('2026-10-%02d', $d), 'balance' => $balance,
            ]);
        }

        return $account->fresh();
    }

    private function calculate(DepositAccount $account, array $overrides = []): \App\Models\ProfitDistribution
    {
        return $this->service->calculate(array_merge([
            'samity_id' => $account->member->samity_id, 'account_type' => 'ALL',
            'profit_basis' => 'A', 'from_date' => '2026-10-01', 'to_date' => '2026-10-31',
            'profit_rate' => '15', 'posting_date' => '2026-10-21',
            'exclude_zero_balance' => true, 'auto_rates' => false,
        ], $overrides), $this->admin);
    }

    public function test_engine_formulas_match_hand_calculations(): void
    {
        $account = $this->accountWithSnapshots();
        $avg = $this->engine->compute($account, 'A', '15', '2026-10-01', '2026-10-31');
        $this->assertSame('127.40', $avg['profit']);
        $this->assertSame('10000.00', $avg['balance']);
        $daily = $this->engine->compute($account, 'D', '15', '2026-10-01', '2026-10-31');
        $this->assertSame('127.40', $daily['profit']);
        $ason = $this->engine->compute($account, 'B', '15', '2026-10-01', '2026-10-31');
        $this->assertSame('127.40', $ason['profit']);
        $none = $this->engine->compute($account, 'N', '15', '2026-10-01', '2026-10-31');
        $this->assertSame('0.00', $none['profit']);

        // Minimum basis on a dipping balance: min 5,000 → 5000×15×31/36500 = 63.70.
        DailyBalance::where('account_id', $account->id)->where('balance_date', '2026-10-15')->update(['balance' => 5000]);
        $min = $this->engine->compute($account, 'M', '15', '2026-10-01', '2026-10-31');
        $this->assertSame('63.70', $min['profit']);
    }

    public function test_distribution_posts_updates_balance_and_profit_payment_flows(): void
    {
        $account = $this->accountWithSnapshots();
        $distribution = $this->calculate($account);
        $this->assertSame('127.40', (string) $distribution->distribution_amount);
        $line = $distribution->lines()->where('account_id', $account->id)->first();
        $this->assertSame('127.40', (string) $line->total_payable);

        $this->service->post($distribution, $this->admin);
        $this->assertSame('50127.40', (string) $account->fresh()->current_balance);
        $this->assertSame('127.40', $this->service->pendingFor($account->fresh()));
        $tb = app(PostingService::class)->trialBalance($account->branch_id);
        $this->assertTrue($tb['balanced']);

        $this->service->payProfit($account->fresh(), '100', '2026-10-21', 'Monthly profit', $this->admin);
        $this->assertSame('50027.40', (string) $account->fresh()->current_balance);
        $this->assertSame('27.40', $this->service->pendingFor($account->fresh()));
        $this->assertSame('100.00', $this->service->paidFor($account->fresh()));
    }

    public function test_exclude_zero_balance_skips_snapshotless_accounts(): void
    {
        $account = $this->accountWithSnapshots();
        $member2 = Member::query()->skip(1)->firstOrFail();
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $empty = app(DepositAccountService::class)->open($member2, $product, [
            'phone' => $member2->mobile, 'opening_date' => '2026-10-01',
        ], $this->admin->id);

        $withExclude = $this->calculate($account);
        $this->assertFalse($withExclude->lines()->where('account_id', $empty->id)->exists());
        $without = $this->calculate($account, ['exclude_zero_balance' => false]);
        $this->assertTrue($without->lines()->where('account_id', $empty->id)->exists());
    }

    public function test_setup_rate_overrides_and_tds_withholding(): void
    {
        $account = $this->accountWithSnapshots();
        ProfitSetup::create([
            'deposit_account_id' => $account->id, 'effective_date' => '2026-10-01',
            'serial' => 1, 'profit_rate' => 10, 'setup_by' => $this->admin->id, 'setup_at' => now(),
        ]);
        // Auto mode uses the per-account setup rate (10%): 10000×10×31/36500 = 84.93.
        $auto = $this->calculate($account, ['auto_rates' => true, 'profit_rate' => '0']);
        $this->assertSame('84.93', (string) $auto->distribution_amount);

        // Withholding 10% on the manual run: net credit = 127.40 − 12.74 = 114.66.
        app(SettingsService::class)->set('profit.tax_withholding_rate', 10);
        $manual = $this->calculate($account);
        $this->service->post($manual, $this->admin);
        $this->assertSame('50114.66', (string) $account->fresh()->current_balance);
    }

    public function test_pages_render(): void
    {
        $account = $this->accountWithSnapshots();
        $member = $account->member;
        $this->actingAs($this->admin)->get('/deposits/profit/setup?member='.$member->member_no)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/ProfitSetup'));
        $this->actingAs($this->admin)->get('/deposits/profit/distribution')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/ProfitDistribution')->where('auto', false));
        $this->actingAs($this->admin)->get('/deposits/profit/distribution/auto')
            ->assertOk()->assertInertia(fn ($p) => $p->where('auto', true));
        $distribution = $this->calculate($account);
        $this->actingAs($this->admin)->get('/deposits/profit/distributions')->assertOk();
        $this->actingAs($this->admin)->get('/deposits/profit/distributions/'.$distribution->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/ProfitDistributionShow'));
        $this->actingAs($this->admin)->get('/deposits/profit/payment?member='.$member->member_no.'&account_id='.$account->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/ProfitPayment'));
        $this->actingAs($this->admin)->get('/deposits/profit/profile?member='.$member->member_no)->assertOk();
    }
}
