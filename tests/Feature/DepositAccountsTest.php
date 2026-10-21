<?php

namespace Tests\Feature;

use App\Models\DepositAccount;
use App\Models\DepositLien;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\OtherAccount;
use App\Models\User;
use App\Services\DepositAccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Step 10 — deposit products, account opening, list, liens, LTS seed. */
class DepositAccountsTest extends TestCase
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

    public function test_open_savings_account_generates_number_and_zero_balances(): void
    {
        $member = $this->member();
        $product = $this->product('SAVINGS');

        $this->actingAs($this->admin)->post('/deposits', [
            'member_id' => $member->id,
            'deposit_product_id' => $product->id,
            'phone' => $member->mobile,
            'opening_date' => '2026-10-21',
        ])->assertRedirect('/deposits');

        $account = DepositAccount::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertNotSame('', $account->account_number);
        $this->assertSame('0.00', (string) $account->current_balance);
        $this->assertSame('0.00', (string) $account->total_credit);
        $this->assertSame((string) $product->default_profit_rate, (string) $account->profit_rate);
        // Default nominee = member's nominee when one exists.
        $this->assertSame($member->nominees()->orderBy('id')->value('id'), $account->nominee_id);

        $this->actingAs($this->admin)->get('/deposits')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/Index'));
        $this->actingAs($this->admin)->get('/deposits?member_id='.$member->member_no)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('accounts.data', 1));
    }

    public function test_fdr_maturity_computed_and_premature_penalty_preview(): void
    {
        $member = $this->member();
        $fdr = $this->product('FDR');
        $fdr->update(['maturity_months' => 12, 'premature_penalty_rate' => 5, 'auto_renew' => true]);

        $account = app(DepositAccountService::class)->open($member, $fdr, [
            'phone' => $member->mobile, 'opening_date' => '2026-01-15',
        ], $this->admin->id);
        $this->assertSame('2027-01-15', $account->maturity_date->toDateString());

        $account->update(['current_balance' => 10000]);
        $preview = app(DepositAccountService::class)->prematureClosePreview($account->fresh(), '2026-07-15');
        $this->assertTrue($preview['is_premature']);
        $this->assertSame('500.00', $preview['penalty']);
        $this->assertSame('9500.00', $preview['payable']);

        $after = app(DepositAccountService::class)->prematureClosePreview($account->fresh(), '2027-02-15');
        $this->assertFalse($after['is_premature']);
        $this->assertSame('0.00', $after['penalty']);
    }

    public function test_active_lien_blocks_closing_and_overwithdrawal(): void
    {
        $member = $this->member();
        $account = app(DepositAccountService::class)->open($member, $this->product('SAVINGS'), [
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
        ], $this->admin->id);
        $account->update(['current_balance' => 1000]);
        DepositLien::create([
            'deposit_account_id' => $account->id, 'amount' => 800, 'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);

        $service = app(DepositAccountService::class);
        $this->assertSame('800.00', $service->activeLienTotal($account));
        try {
            $service->assertClosable($account);
            $this->fail('Closing should be blocked by an active lien.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $service->assertWithdrawable($account, '300');
            $this->fail('Withdrawal below the lien should be blocked.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $service->assertWithdrawable($account, '200'); // exactly the free part
        $this->addToAssertionCount(1);
    }

    public function test_long_term_savings_seed_matches_printed_table_and_is_editable(): void
    {
        $lts = $this->product('LTS');
        $this->assertSame('দীর্ঘ মেয়াদী সঞ্চয়', $lts->name_bn);
        $config = $lts->config;
        $this->assertSame([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000], $config['fixed_amounts']);
        $this->assertSame(260, $config['collection_days_per_year']);
        // Printed cross-check rows (photo transcription).
        $this->assertSame([456, 1648, 3889, 7521, 12963], array_values($config['payout_table']['50']));
        $this->assertSame([9135, 32991, 77760, 150479, 259339], array_values($config['payout_table']['1000']));
        $this->assertSame([913, 3298, 7774, 15045, 25930], array_values($config['payout_table']['100']));
        $this->assertSame([8221, 29691, 69983, 135430, 233404], array_values($config['payout_table']['900']));
        $this->assertSame(0, $config['premature_slabs'][0]['rate']);
        $this->assertSame(13, $config['premature_slabs'][4]['rate']);

        // Opening an LTS account with a 5-year term computes maturity from config terms.
        $member = $this->member();
        $this->actingAs($this->admin)->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $lts->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
            'amount' => 100, 'term_months' => 60,
        ])->assertRedirect('/deposits');
        $account = DepositAccount::where('deposit_product_id', $lts->id)->latest('id')->firstOrFail();
        $this->assertSame('2031-10-21', $account->maturity_date->toDateString());
        $this->assertSame('100.00', (string) $account->installment_amount);

        // The admin can edit the sample's figures like any product data…
        $config['payout_table']['50']['1'] = 999;
        $this->actingAs($this->admin)->put('/deposits/products/'.$lts->id, [
            'code' => 'LTS', 'name_en' => 'Long Term Savings', 'name_bn' => 'দীর্ঘ মেয়াদী সঞ্চয়',
            'gl_mapping_prefix' => 'deposit.dps', 'default_profit_rate' => 15, 'config' => $config,
        ])->assertRedirect();
        $this->assertSame(999, $lts->fresh()->config['payout_table']['50']['1']);

        // …and delete a product that has no accounts.
        $tmp = DepositProduct::create(['code' => 'TMPX', 'name_en' => 'Temp', 'gl_mapping_prefix' => 'deposit.savings']);
        $this->actingAs($this->admin)->delete('/deposits/products/'.$tmp->id)->assertRedirect();
        $this->assertNull(DepositProduct::find($tmp->id));
    }

    public function test_other_account_opening(): void
    {
        $this->actingAs($this->admin)->post('/deposits/other', [
            'account_type' => 'Office', 'name' => 'Petty Cash Fund', 'opening_date' => '2026-10-21',
            'balance' => 5000, 'comments' => 'Opening balance',
        ])->assertRedirect('/deposits/other');
        $account = OtherAccount::where('name', 'Petty Cash Fund')->firstOrFail();
        $this->assertNotSame('', $account->account_number);
        $this->assertSame('5000.00', (string) $account->balance);
        $this->actingAs($this->admin)->get('/deposits/other')->assertOk();
        $this->actingAs($this->admin)->get('/deposits/opening?member='.$this->member()->member_no)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/Opening')->where('member.member_no', $this->member()->member_no));
    }

    public function test_voucher_lines_deposit_fk_enforced(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('deposit_accounts'));
        $fks = collect(\Illuminate\Support\Facades\DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voucher_lines' AND COLUMN_NAME = 'deposit_account_id' AND REFERENCED_TABLE_NAME = 'deposit_accounts'"
        ));
        $this->assertNotEmpty($fks);
    }
}
