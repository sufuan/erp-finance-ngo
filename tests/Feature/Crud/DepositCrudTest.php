<?php

namespace Tests\Feature\Crud;

use App\Models\DailyBalance;
use App\Models\DepositAccount;
use App\Models\DepositBlock;
use App\Models\DepositClosing;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\DepositTransfer;
use App\Models\Member;
use App\Models\OtherAccount;
use App\Models\ProfitPayment;
use App\Models\ProfitSetup;
use App\Models\TransactionProfile;
use App\Services\ProfitDistributionService;

/**
 * Deposits: product CRUD (incl. the LTS sample's config), account
 * opening for savings + DPS + LTS, money movement (receive / payment /
 * transfer / cancel), shares, blocks, profit setup + distribution run +
 * payment, closing, and the report pages with filters.
 */
class DepositCrudTest extends CrudTestCase
{
    private int $memberSeq = 0;

    private function makeMember(): Member
    {
        $this->memberSeq++;
        $mobile = '0171200000'.$this->memberSeq;
        $this->post('/members', [
            'samity_id' => $this->samity()->id, 'name' => 'Deposit CRUD Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199620000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::where('mobile', $mobile)->firstOrFail();
    }

    private function product(string $code): DepositProduct
    {
        return DepositProduct::where('code', $code)->firstOrFail();
    }

    private function openAccount(Member $member, DepositProduct $product, array $extra = []): DepositAccount
    {
        $this->post('/deposits', array_merge([
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
        ], $extra))->assertRedirect('/deposits')->assertSessionHas('success');

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->latest('id')->firstOrFail();
    }

    public function test_deposit_products_crud_and_lts_config_edit(): void
    {
        $payload = [
            'code' => 'CRUDSAV', 'name_en' => 'CRUD Savings', 'name_bn' => 'ক্রুড সঞ্চয়',
            'is_compulsory' => false, 'default_profit_rate' => 6.5,
            'minimum_balance' => 100, 'minimum_opening_amount' => 500,
            'withdrawal_rules' => ['max_per_day' => 1], 'maturity_months' => null,
            'premature_penalty_rate' => 2, 'auto_renew' => false,
            'gl_mapping_prefix' => 'deposit.crud', 'config' => ['note' => 'crud'],
            'is_active' => true,
        ];
        $this->post('/deposits/products', $payload)->assertRedirect()->assertSessionHas('success');
        $product = $this->product('CRUDSAV');
        $this->assertSame('6.50', (string) $product->default_profit_rate);

        $this->get('/deposits/products')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Deposits/Products'));

        $payload['name_en'] = 'CRUD Savings Renamed';
        $payload['config'] = ['note' => 'crud-edited'];
        $this->put("/deposits/products/{$product->id}", $payload)
            ->assertRedirect()->assertSessionHas('success');
        $product->refresh();
        $this->assertSame('CRUD Savings Renamed', $product->name_en);
        $this->assertSame('crud-edited', $product->config['note']);

        $this->delete("/deposits/products/{$product->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(DepositProduct::find($product->id));

        // The seeded LTS sample stays fully editable — change one printed
        // payout figure through the real update route.
        $lts = $this->product('LTS');
        $config = $lts->config;
        $config['payout_table']['50']['1'] = 777;
        $this->put("/deposits/products/{$lts->id}", [
            'code' => 'LTS', 'name_en' => $lts->name_en, 'name_bn' => $lts->name_bn,
            'gl_mapping_prefix' => $lts->gl_mapping_prefix,
            'default_profit_rate' => $lts->default_profit_rate, 'config' => $config,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(777, $lts->fresh()->config['payout_table']['50']['1']);

        // Designed guard: a product WITH accounts refuses deletion (422).
        $member = $this->makeMember();
        $this->openAccount($member, $this->product('SAVINGS'));
        $this->delete('/deposits/products/'.$this->product('SAVINGS')->id)->assertStatus(422);
        $this->assertNotNull(DepositProduct::where('code', 'SAVINGS')->first());
    }

    public function test_deposit_account_full_money_lifecycle(): void
    {
        $member = $this->makeMember();

        $savings = $this->openAccount($member, $this->product('SAVINGS'), ['title' => 'CRUD Savings A/c']);
        $dps = $this->openAccount($member, $this->product('DPS'), ['amount' => 500, 'term_months' => 12]);
        $lts = $this->openAccount($member, $this->product('LTS'), ['amount' => 100, 'term_months' => 60]);
        $this->assertSame('500.00', (string) $dps->installment_amount);
        $this->assertSame('2031-10-21', $lts->maturity_date->toDateString());

        $this->get('/deposits')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Deposits/Index'));
        $this->get('/deposits/opening?member='.$member->member_no)->assertOk();

        // Receive / payment.
        $this->post('/deposits/receive', [
            'account_id' => $savings->id, 'amount' => 5000, 'document_number' => 'CRUD-R1',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('5000.00', (string) $savings->fresh()->current_balance);

        $this->post('/deposits/receive', ['account_id' => $dps->id, 'amount' => 500])
            ->assertRedirect()->assertSessionHas('success');

        $this->post('/deposits/payment', [
            'account_id' => $savings->id, 'amount' => 1500, 'document_number' => 'CRUD-P1',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('3500.00', (string) $savings->fresh()->current_balance);
        $paymentTxn = DepositTransaction::where('account_id', $savings->id)
            ->where('document_number', 'CRUD-P1')->firstOrFail();

        // Transfer between the member's own accounts (UI offers it).
        $this->post('/deposits/transfer', [
            'from_account_id' => $savings->id, 'to_account_id' => $dps->id,
            'amount' => 1000, 'comments' => 'CRUD move to DPS',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('2500.00', (string) $savings->fresh()->current_balance);
        $this->assertSame('1500.00', (string) $dps->fresh()->current_balance);
        $transfer = DepositTransfer::where('from_account_id', $savings->id)->firstOrFail();

        // Cancel the transfer, then cancel the payment — both reverse.
        $this->post("/deposits/transfers/{$transfer->id}/cancel", ['reason' => 'CRUD cancel transfer'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('3500.00', (string) $savings->fresh()->current_balance);
        $this->assertSame('500.00', (string) $dps->fresh()->current_balance);

        $this->post("/deposits/transactions/{$paymentTxn->id}/cancel", ['reason' => 'CRUD cancel payment'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('5000.00', (string) $savings->fresh()->current_balance);
        $this->assertNotNull($paymentTxn->fresh()->cancel_at);

        // Shares: receive then partial payment against the member.
        $shareBefore = (string) $member->fresh()->share_amount;
        $this->post('/deposits/share', [
            'member_id' => $member->id, 'mode' => 'receive', 'amount' => 200,
        ])->assertRedirect()->assertSessionHas('success');
        $this->post('/deposits/share', [
            'member_id' => $member->id, 'mode' => 'payment', 'amount' => 100,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(
            number_format((float) $shareBefore + 100, 2, '.', ''),
            (string) $member->fresh()->share_amount
        );

        // Blocks: set a withdrawal block on the DPS account, then clear it.
        $this->post('/deposits/blocks', [
            'account_id' => $dps->id, 'block_effective_date' => '2026-10-21',
            'block_amount' => 500, 'reason' => 'CRUD hold', 'withdrawal_block' => true,
        ])->assertRedirect()->assertSessionHas('success');
        $block = DepositBlock::where('account_id', $dps->id)->firstOrFail();
        $this->get('/deposits/blocks')->assertOk();
        $this->get('/deposits/blocks/clear')->assertOk();
        $this->post("/deposits/blocks/{$block->id}/clear", [
            'clear_date' => '2026-10-21', 'clear_amount' => 500, 'clear_reason' => 'CRUD released',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($block->fresh()->clear_date);

        // Profit setup + installment profile through their forms.
        $this->post('/deposits/profit/setup', [
            'account_id' => $savings->id, 'effective_date' => '2026-10-21', 'profit_rate' => 10,
        ])->assertRedirect()->assertSessionHas('success');
        $setup = ProfitSetup::where('deposit_account_id', $savings->id)->firstOrFail();
        $this->post("/deposits/profit/setup/{$setup->id}/cancel")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($setup->fresh()->cancelled_at);

        $this->post('/deposits/profit/profile', [
            'account_id' => $dps->id, 'installment_amount' => 600,
            'from_date' => '2026-10-21', 'to_date' => '2027-10-21', 'comments' => 'CRUD profile',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, TransactionProfile::where('deposit_account_id', $dps->id)->count());
        $this->assertSame('600.00', (string) $dps->fresh()->installment_amount);

        // Closing the DPS account, then cancelling the closing reopens it.
        $this->get('/deposits/closing')->assertOk();
        $this->post('/deposits/closing', [
            'account_id' => $dps->id, 'closing_date' => '2026-10-21', 'reason' => 'CRUD close',
        ])->assertRedirect()->assertSessionHas('success');
        $closing = DepositClosing::where('account_id', $dps->id)->firstOrFail();
        $this->assertNotNull($dps->fresh()->closing_date);

        $this->post("/deposits/closing/{$closing->id}/cancel")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull($dps->fresh()->closing_date);
        $this->assertNotNull($closing->fresh()->cancelled_at);

        // Other (non-member) account opening.
        $this->post('/deposits/other', [
            'account_type' => 'Office', 'name' => 'CRUD Petty Cash', 'opening_date' => '2026-10-21',
            'balance' => 2500, 'comments' => 'CRUD opening',
        ])->assertRedirect('/deposits/other')->assertSessionHas('success');
        $this->assertSame('2500.00', (string) OtherAccount::where('name', 'CRUD Petty Cash')->firstOrFail()->balance);
    }

    public function test_profit_distribution_run_post_and_payment_over_http(): void
    {
        $member = $this->makeMember();
        $account = $this->openAccount($member, $this->product('SAVINGS'), ['opening_date' => '2026-10-01']);
        $this->post('/deposits/receive', ['account_id' => $account->id, 'amount' => 10000])
            ->assertRedirect()->assertSessionHas('success');

        // Daily-balance snapshots are the engine's fixture: a month of
        // steady balance so the distribution has something due.
        for ($d = 1; $d <= 21; $d++) {
            DailyBalance::create([
                'account_type' => 'deposit', 'account_id' => $account->id,
                'balance_date' => sprintf('2026-10-%02d', $d), 'balance' => 10000,
            ]);
        }

        $this->get('/deposits/profit/distribution')->assertOk();
        $response = $this->post('/deposits/profit/distributions', [
            'samity_id' => $member->samity_id, 'account_type' => 'ALL', 'profit_basis' => 'A',
            'from_date' => '2026-10-01', 'to_date' => '2026-10-21', 'profit_rate' => 15,
            'posting_date' => '2026-10-21', 'exclude_zero_balance' => true,
        ]);
        $response->assertRedirect();
        $distributionId = (int) basename($response->headers->get('Location'));
        $this->assertGreaterThan(0, $distributionId);

        $this->get("/deposits/profit/distributions/{$distributionId}")->assertOk();
        $this->get('/deposits/profit/distributions')->assertOk();

        $this->post("/deposits/profit/distributions/{$distributionId}/post")
            ->assertRedirect()->assertSessionHas('success');

        $pending = app(ProfitDistributionService::class)->pendingFor($account->fresh());
        $this->assertGreaterThan(0, (float) $pending);

        $this->post('/deposits/profit/payment', [
            'account_id' => $account->id, 'profit_amount' => $pending,
            'payment_date' => '2026-10-21', 'comments' => 'CRUD profit payout',
        ])->assertRedirect()->assertSessionHas('success');
        $payment = ProfitPayment::where('deposit_account_id', $account->id)->firstOrFail();
        $this->assertSame($pending, (string) $payment->profit_amount);
    }

    public function test_deposit_report_pages_render_with_filters(): void
    {
        $member = $this->makeMember();
        $account = $this->openAccount($member, $this->product('SAVINGS'));
        $this->post('/deposits/receive', ['account_id' => $account->id, 'amount' => 1000])
            ->assertSessionHas('success');

        foreach (['savings_register', 'dps_register', 'fdr_register', 'collection_register',
            'profit_payment_register', 'zero_transaction_report'] as $key) {
            $this->get("/reports/deposits?report={$key}&run=1&from_date=2026-10-01&to_date=2026-10-31")
                ->assertOk()
                ->assertInertia(fn ($p) => $p->component('Reports/DepositReports'));
        }
        $this->get('/reports/deposits?report=monthly_deposit_transaction&run=1&year=2026')
            ->assertOk();
        $this->get('/reports/deposits?report=dps_target_vs_collection&run=1&from_date=2026-10-01&to_date=2026-10-31')
            ->assertOk();

        $pdf = $this->get('/reports/deposits/savings_register/pdf?from_date=2026-10-01&to_date=2026-10-31');
        $pdf->assertOk();
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('content-type'));
    }
}
