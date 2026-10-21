<?php

namespace Tests\Feature;

use App\Models\DepositAccount;
use App\Models\DepositBlock;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\DepositAccountService;
use App\Services\DepositClosingService;
use App\Services\DepositTransactionService;
use App\Services\PostingService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Step 12 — deposit blocks gate payments; closing zeroes with a voucher. */
class DepositBlocksClosingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private DepositTransactionService $transactions;
    private DepositClosingService $closings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->transactions = app(DepositTransactionService::class);
        $this->closings = app(DepositClosingService::class);
    }

    private function fundedAccount(string $code = 'SAVINGS', string $amount = '1000'): DepositAccount
    {
        $member = Member::query()->firstOrFail();
        $product = DepositProduct::where('code', $code)->firstOrFail();
        $account = app(DepositAccountService::class)->open($member, $product, [
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
        ], $this->admin->id);
        $this->transactions->receive($account, $amount, null, null, $this->admin);

        return $account->fresh();
    }

    public function test_withdrawal_block_gates_payment_until_cleared(): void
    {
        $account = $this->fundedAccount();
        $block = DepositBlock::create([
            'account_id' => $account->id, 'block_effective_date' => '2026-10-21',
            'block_amount' => 400, 'reason' => 'Court order',
            'deposit_block' => false, 'withdrawal_block' => true,
            'block_by' => $this->admin->id, 'block_at' => now(),
        ]);

        // 700 would leave 300 < 400 blocked.
        try {
            $this->transactions->payment($account, '700', null, null, $this->admin);
            $this->fail('Payment into the blocked amount should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        // 600 leaves exactly the blocked 400 — allowed.
        $this->transactions->payment($account, '600', null, null, $this->admin);
        $this->assertSame('400.00', (string) $account->fresh()->current_balance);

        // A deposit block stops receives too.
        $block2 = DepositBlock::create([
            'account_id' => $account->id, 'block_effective_date' => '2026-10-21',
            'block_amount' => 100, 'reason' => 'Audit hold',
            'deposit_block' => true, 'withdrawal_block' => false,
            'block_by' => $this->admin->id, 'block_at' => now(),
        ]);
        try {
            $this->transactions->receive($account, '50', null, null, $this->admin);
            $this->fail('Receive under a deposit block should fail.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // Clear both blocks; the rest can move again.
        $block->update(['clear_date' => '2026-10-21', 'clear_amount' => 400, 'clear_reason' => 'Order lifted', 'cleared_by' => $this->admin->id, 'cleared_at' => now()]);
        $block2->update(['clear_date' => '2026-10-21', 'clear_amount' => 100, 'clear_reason' => 'Audit done', 'cleared_by' => $this->admin->id, 'cleared_at' => now()]);
        $this->transactions->payment($account->fresh(), '400', null, null, $this->admin);
        $this->assertSame('0.00', (string) $account->fresh()->current_balance);
    }

    public function test_closing_pays_balance_plus_profit_minus_charge_and_penalty(): void
    {
        app(SettingsService::class)->set('deposit.closing_charge', 25);
        $fdr = DepositProduct::where('code', 'FDR')->firstOrFail();
        $fdr->update(['maturity_months' => 12, 'premature_penalty_rate' => 5]);
        $account = $this->fundedAccount('FDR', '1000');

        $preview = $this->closings->preview($account, '2026-10-21', '100');
        $this->assertSame('1000.00', $preview['balance']);
        $this->assertSame('50.00', $preview['penalty']); // 5% premature
        $this->assertSame('25.00', $preview['closing_charge']);
        $this->assertSame('1025.00', $preview['payable']); // 1000 + 100 − 25 − 50

        $closing = $this->closings->close($account, '2026-10-21', 'Member request', $this->admin, '100');
        $fresh = $account->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('2026-10-21', $fresh->closing_date->toDateString());
        $this->assertSame('0.00', (string) $fresh->current_balance);
        $this->assertSame('1025.00', (string) $closing->payable_amount);
        $tb = app(PostingService::class)->trialBalance($account->branch_id);
        $this->assertTrue($tb['balanced']);

        // Cancel reopens the account with the balance restored.
        $this->closings->cancelClosing($closing, $this->admin);
        $fresh = $account->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->closing_date);
        $this->assertSame('1000.00', (string) $fresh->current_balance);
        $this->assertNotNull($closing->fresh()->cancelled_at);
    }

    public function test_zero_balance_closing_needs_no_voucher(): void
    {
        $member = Member::query()->firstOrFail();
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $account = app(DepositAccountService::class)->open($member, $product, [
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
        ], $this->admin->id);
        $closing = $this->closings->close($account, '2026-10-21', 'Dormant', $this->admin);
        $this->assertSame('0.00', (string) $closing->payable_amount);
        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_pages_render(): void
    {
        $account = $this->fundedAccount();
        $member = $account->member;
        $this->actingAs($this->admin)->get('/deposits/blocks?member='.$member->member_no)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/Blocks'));
        $this->actingAs($this->admin)->get('/deposits/blocks/clear?member='.$member->member_no)->assertOk();
        $this->actingAs($this->admin)->get('/deposits/closing?member='.$member->member_no.'&account_id='.$account->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/Closing')->where('preview.balance', '1000.00'));
    }
}
