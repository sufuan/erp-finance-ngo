<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositClosingService;
use App\Services\DepositTransactionService;
use App\Services\LtsProfitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Client requirements 2026-11-18, Feature 3 — Long Term Savings
 * profit per the printed terms: slab amounts and 1–5 year terms are
 * enforced at opening; at maturity the profit is the printed table
 * figure scaled by collected days ÷ (260 × years); closing early
 * pays the premature slab % of the total deposited.
 */
class LtsProfitTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::where('code', '102')->firstOrFail();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
        $samity = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'LTS Member',
            'gender' => 'Female', 'mobile' => '01717000001', 'national_id' => '1998500001',
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');
        $this->member = Member::withoutGlobalScopes()->where('mobile', '01717000001')->firstOrFail();
    }

    private function ltsProduct(): DepositProduct
    {
        return DepositProduct::where('code', 'LTS')->firstOrFail();
    }

    private function openLts(string $openingDate, int $daily, int $termMonths): DepositAccount
    {
        return app(DepositAccountService::class)->open($this->member, $this->ltsProduct(), [
            'opening_date' => $openingDate,
            'installment_amount' => $daily,
            'term_months' => $termMonths,
        ], $this->admin->id);
    }

    /** Deposit a total in a few engine receives (day-equivalents count from totals). */
    private function depositTotal(DepositAccount $account, string $total): void
    {
        $remaining = (float) $total;
        while ($remaining > 0) {
            $chunk = min($remaining, 5000);
            app(DepositTransactionService::class)->receive($account, (string) $chunk, null, 'LTS collection', $this->admin);
            $remaining -= $chunk;
        }
    }

    public function test_opening_rejects_amounts_outside_the_printed_slabs(): void
    {
        $this->post('/deposits', [
            'member_id' => $this->member->id, 'deposit_product_id' => $this->ltsProduct()->id,
            'phone' => $this->member->mobile, 'opening_date' => '2026-10-21',
            'amount' => 75, 'term_months' => 12,
        ])->assertSessionHasErrors();
        $this->assertSame(0, DepositAccount::where('member_id', $this->member->id)
            ->where('deposit_product_id', $this->ltsProduct()->id)->count());
    }

    public function test_opening_rejects_terms_outside_one_to_five_years(): void
    {
        $this->post('/deposits', [
            'member_id' => $this->member->id, 'deposit_product_id' => $this->ltsProduct()->id,
            'phone' => $this->member->mobile, 'opening_date' => '2026-10-21',
            'amount' => 100, 'term_months' => 72,
        ])->assertSessionHasErrors();
        $this->assertSame(0, DepositAccount::where('member_id', $this->member->id)
            ->where('deposit_product_id', $this->ltsProduct()->id)->count());
    }

    public function test_maturity_pays_the_full_printed_profit_when_fully_collected(): void
    {
        // 50/day, 1 year: 260 collected days × 50 = 13,000 deposited;
        // printed profit for (50, 1y) = 456.
        $account = $this->openLts('2025-01-06', 50, 12);
        $this->depositTotal($account, '13000');

        $calc = app(LtsProfitService::class)->compute($account->fresh(), '2026-01-06');
        $this->assertSame('maturity', $calc['basis']);
        $this->assertSame('456.00', $calc['profit']);

        $closing = app(DepositClosingService::class)->close($account, '2026-01-06', 'Matured', $this->admin, '0');
        $this->assertSame('456.00', (string) $closing->profit_payment);
        $this->assertSame('13456.00', (string) $closing->payable_amount);
        $this->assertSame('0.00', (string) $account->fresh()->current_balance);
        $this->assertNotNull($account->fresh()->closing_date);
    }

    public function test_maturity_profit_scales_pro_rata_with_collected_days(): void
    {
        // 100/day, 1 year, only 130 of 260 days collected (13,000 of
        // 26,000): profit = 913 × 130/260 = 456.50.
        $account = $this->openLts('2025-01-06', 100, 12);
        $this->depositTotal($account, '13000');

        $calc = app(LtsProfitService::class)->compute($account->fresh(), '2026-01-06');
        $this->assertSame('maturity', $calc['basis']);
        $this->assertSame('456.50', $calc['profit']);
    }

    public function test_maturity_uses_the_authoritative_printed_table(): void
    {
        // 200/day, 5 years, fully collected: 260 × 5 × 200 = 260,000;
        // the printed table says 51,866 (cell re-read at 10x zoom
        // against the re-sent sheet photo, 2026-11-18).
        $account = $this->openLts('2021-01-04', 200, 60);
        $this->depositTotal($account, '260000');

        $calc = app(LtsProfitService::class)->compute($account->fresh(), '2026-01-04');
        $this->assertSame('maturity', $calc['basis']);
        $this->assertSame('51866.00', $calc['profit']);
    }

    public function test_premature_closing_pays_the_slab_percent_of_total_deposited(): void
    {
        // Opened 2024-01-01, closed 2026-06-01 → 2 completed years →
        // 9% of the 20,000 deposited = 1,800.
        $account = $this->openLts('2024-01-01', 200, 60);
        $this->depositTotal($account, '20000');

        $calc = app(LtsProfitService::class)->compute($account->fresh(), '2026-06-01');
        $this->assertSame('premature', $calc['basis']);
        $this->assertSame('9.00', $calc['slab_rate']);
        $this->assertSame('1800.00', $calc['profit']);

        $closing = app(DepositClosingService::class)->close($account, '2026-06-01', 'Member request', $this->admin, '0');
        $this->assertSame('1800.00', (string) $closing->profit_payment);
        $this->assertSame('21800.00', (string) $closing->payable_amount);
    }

    public function test_premature_closing_within_the_first_year_pays_no_profit(): void
    {
        $account = $this->openLts('2026-01-01', 100, 36);
        $this->depositTotal($account, '5000');

        $calc = app(LtsProfitService::class)->compute($account->fresh(), '2026-06-01');
        $this->assertSame('premature', $calc['basis']);
        $this->assertSame('0.00', $calc['profit']);
    }

    public function test_preview_carries_the_lts_figures_for_the_closing_screen(): void
    {
        $account = $this->openLts('2025-01-06', 50, 12);
        $this->depositTotal($account, '13000');

        $preview = app(DepositClosingService::class)->preview($account->fresh(), '2026-01-06', '999');
        $this->assertSame('456.00', $preview['profit_payment'], 'The computed LTS profit replaces any typed figure');
        $this->assertSame('maturity', $preview['lts']['basis']);
    }

    public function test_non_lts_products_keep_the_manual_profit_behaviour(): void
    {
        $savings = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $account = app(DepositAccountService::class)->open($this->member, $savings, [
            'opening_date' => '2026-10-21',
        ], $this->admin->id);
        $this->assertNull(app(LtsProfitService::class)->compute($account, '2026-11-01'));
        $preview = app(DepositClosingService::class)->preview($account->fresh(), '2026-11-01', '250');
        $this->assertSame('250.00', $preview['profit_payment']);
        $this->assertNull($preview['lts']);
    }
}
