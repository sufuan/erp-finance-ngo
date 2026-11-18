<?php

namespace Tests\Feature;

use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\ProfitDistributionLine;
use App\Models\Samity;
use App\Models\User;
use App\Services\ProfitDistributionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * LTS opening (owner order, 2026-11-18): a Long Term Savings account
 * is priced by the printed payout table (daily amount x term years),
 * NEVER by a flat profit rate. The opening form hides the rate field
 * for LTS; the account stores profit_rate 0 even if a rate is posted;
 * the slab/term guard rejects off-table values; flat-rate profit
 * machinery (distribution runs, per-account rate setups) excludes LTS.
 */
class LtsOpeningFormTest extends TestCase
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

    private function member(): Member
    {
        return Member::query()->firstOrFail();
    }

    private function product(string $code): DepositProduct
    {
        return DepositProduct::where('code', $code)->firstOrFail();
    }

    private function openPayload(Member $member, DepositProduct $product, array $extra = []): array
    {
        return array_merge([
            'member_id' => $member->id,
            'deposit_product_id' => $product->id,
            'phone' => $member->mobile,
            'opening_date' => '2026-11-18',
        ], $extra);
    }

    public function test_lts_opening_stores_no_flat_rate_even_when_one_is_posted(): void
    {
        $member = $this->member();
        $lts = $this->product('LTS');

        // The old form posted the product default 15.00 — even if a rate
        // is posted, the LTS account must store 0 (profit comes from the
        // printed table at closing).
        $this->actingAs($this->admin)->post('/deposits', $this->openPayload($member, $lts, [
            'amount' => 100, 'term_months' => 36, 'profit_rate' => 15,
        ]))->assertRedirect('/deposits')->assertSessionHasNoErrors();

        $account = DepositAccount::where('deposit_product_id', $lts->id)->latest('id')->firstOrFail();
        $this->assertSame('0.00', (string) $account->profit_rate);
        $this->assertSame('100.00', (string) $account->installment_amount);
        $this->assertSame('2029-11-18', $account->maturity_date->toDateString());

        // The printed table for 100/day x 3 years (the figure the form
        // preview shows and the closing engine pays).
        $this->assertSame(7774, (int) $lts->config['payout_table']['100']['3']);
    }

    public function test_lts_opening_rejects_non_slab_amount_and_out_of_range_term(): void
    {
        $member = $this->member();
        $lts = $this->product('LTS');

        $this->actingAs($this->admin)->post('/deposits', $this->openPayload($member, $lts, [
            'amount' => 75, 'term_months' => 36,
        ]))->assertSessionHasErrors('installment_amount');

        $this->actingAs($this->admin)->post('/deposits', $this->openPayload($member, $lts, [
            'amount' => 100, 'term_months' => 72,
        ]))->assertSessionHasErrors('term_months');

        $this->assertSame(0, DepositAccount::where('deposit_product_id', $lts->id)
            ->where('member_id', $member->id)->whereDate('opening_date', '2026-11-18')->count());
    }

    public function test_savings_opening_still_accepts_a_profit_rate(): void
    {
        $member = $this->member();
        $savings = $this->product('SAVINGS');

        $this->actingAs($this->admin)->post('/deposits', $this->openPayload($member, $savings, [
            'profit_rate' => 12.5,
        ]))->assertRedirect('/deposits')->assertSessionHasNoErrors();

        $account = DepositAccount::where('deposit_product_id', $savings->id)->latest('id')->firstOrFail();
        $this->assertSame('12.50', (string) $account->profit_rate);
    }

    public function test_profit_setup_is_rejected_for_lts_accounts(): void
    {
        $member = $this->member();
        $lts = $this->product('LTS');
        $this->actingAs($this->admin)->post('/deposits', $this->openPayload($member, $lts, [
            'amount' => 100, 'term_months' => 36,
        ]))->assertSessionHasNoErrors();
        $account = DepositAccount::where('deposit_product_id', $lts->id)->latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post('/deposits/profit/setup', [
            'account_id' => $account->id, 'effective_date' => '2026-11-18', 'profit_rate' => 10,
        ])->assertSessionHasErrors('profit_rate');
    }

    public function test_flat_rate_distribution_skips_lts_accounts(): void
    {
        $member = $this->member();
        $samity = Samity::findOrFail($member->samity_id);
        $lts = $this->product('LTS');
        $savings = $this->product('SAVINGS');

        $this->actingAs($this->admin)->post('/deposits', $this->openPayload($member, $lts, [
            'amount' => 100, 'term_months' => 36,
        ]))->assertSessionHasNoErrors();
        $ltsAccount = DepositAccount::where('deposit_product_id', $lts->id)->latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post('/deposits', $this->openPayload($member, $savings, [
            'amount' => 500,
        ]))->assertSessionHasNoErrors();
        $savingsAccount = DepositAccount::where('deposit_product_id', $savings->id)->latest('id')->firstOrFail();

        $distribution = app(ProfitDistributionService::class)->calculate([
            'samity_id' => $samity->id, 'account_type' => 'ALL', 'profit_basis' => 'A',
            'from_date' => '2026-11-01', 'to_date' => '2026-11-18', 'profit_rate' => '10',
            'posting_date' => '2026-11-18', 'exclude_zero_balance' => false, 'auto_rates' => false,
        ], $this->admin);

        $lineAccountIds = ProfitDistributionLine::where('distribution_id', $distribution->id)
            ->pluck('account_id')->all();
        $this->assertContains($savingsAccount->id, $lineAccountIds);
        $this->assertNotContains($ltsAccount->id, $lineAccountIds);
    }

    public function test_lts_config_table_matches_the_verified_sheet_cells(): void
    {
        $table = $this->product('LTS')->config['payout_table'];
        // Cells corrected to the printed sheet on 2026-11-18 (sheet wins).
        $this->assertSame(51866, (int) $table['200']['5']);
        $this->assertSame(16495, (int) $table['500']['2']);
        $this->assertSame(75238, (int) $table['500']['4']);
        $this->assertSame(129668, (int) $table['500']['5']);
        $this->assertSame(90286, (int) $table['600']['4']);
        $this->assertSame(54431, (int) $table['700']['3']);
        $this->assertSame(105334, (int) $table['700']['4']);
        $this->assertSame(181536, (int) $table['700']['5']);
        $this->assertSame(207490, (int) $table['800']['5']);
        $this->assertSame(135430, (int) $table['900']['4']);
        $this->assertSame(259309, (int) $table['1000']['5']);
    }
}
