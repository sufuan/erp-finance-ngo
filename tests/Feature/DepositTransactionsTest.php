<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\Ledger;
use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Step 11 — deposit receive/payment/transfer/share through PostingService. */
class DepositTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private DepositTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->service = app(DepositTransactionService::class);
    }

    private function openAccount(string $productCode = 'SAVINGS', ?Member $member = null): DepositAccount
    {
        $member ??= Member::query()->firstOrFail();
        $product = DepositProduct::where('code', $productCode)->firstOrFail();

        return app(DepositAccountService::class)->open($member, $product, [
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
        ], $this->admin->id);
    }

    public function test_receive_then_payment_moves_balance_with_balanced_vouchers(): void
    {
        $account = $this->openAccount();

        $detail = $this->service->receive($account, '1000', 'DOC-1', 'Cash deposit', $this->admin);
        $account->refresh();
        $this->assertSame('1000.00', (string) $account->current_balance);
        $this->assertSame('1000.00', (string) $account->total_credit);
        $this->assertNotNull($detail->voucher_id);
        $this->assertSame('Receive', $detail->tran_type);
        $this->assertDatabaseHas('account_transactions', [
            'account_type' => 'deposit', 'account_id' => $account->id,
            'tran_code' => 'RECEIVE', 'balance_after' => '1000.00',
        ]);

        $payment = $this->service->payment($account, '200', null, 'Withdrawal', $this->admin);
        $account->refresh();
        $this->assertSame('800.00', (string) $account->current_balance);
        $this->assertSame('200.00', (string) $account->total_debit);

        // Every movement carries a balanced voucher; the branch TB balances.
        foreach ([$detail, $payment] as $row) {
            $voucher = Voucher::findOrFail($row->voucher_id);
            $debit = $voucher->lines()->sum('debit');
            $credit = $voucher->lines()->sum('credit');
            $this->assertSame((string) $debit, (string) $credit);
        }
        $tb = app(PostingService::class)->trialBalance($account->branch_id);
        $this->assertTrue($tb['balanced']);
        // GL savings control now holds 800 credit-normal.
        $this->assertSame('800.00', app(PostingService::class)->ledgerBalance(Ledger::byCode('2010'), $account->branch_id));
    }

    public function test_payment_beyond_balance_is_rejected(): void
    {
        $account = $this->openAccount();
        $this->service->receive($account, '300', null, null, $this->admin);
        try {
            $this->service->payment($account->fresh(), '301', null, null, $this->admin);
            $this->fail('Overpayment should be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('300.00', (string) $account->fresh()->current_balance);
    }

    public function test_transfer_moves_between_accounts_without_leak_and_cancels(): void
    {
        $memberA = Member::query()->firstOrFail();
        $memberB = Member::query()->skip(1)->firstOrFail();
        $from = $this->openAccount('SAVINGS', $memberA);
        $to = $this->openAccount('DPS', $memberB);
        $this->service->receive($from, '1000', null, null, $this->admin);

        $sumBefore = (float) $from->fresh()->current_balance + (float) $to->fresh()->current_balance;
        $transfer = $this->service->transfer($from, $to, '400', 'TR-1', 'Move to DPS', $this->admin);
        $this->assertSame('600.00', (string) $from->fresh()->current_balance);
        $this->assertSame('400.00', (string) $to->fresh()->current_balance);
        $sumAfter = (float) $from->fresh()->current_balance + (float) $to->fresh()->current_balance;
        $this->assertSame($sumBefore, $sumAfter);

        $this->service->cancelTransfer($transfer, $this->admin, 'Wrong account');
        $this->assertSame('1000.00', (string) $from->fresh()->current_balance);
        $this->assertSame('0.00', (string) $to->fresh()->current_balance);
        $this->assertNotNull($transfer->fresh()->cancel_at);
    }

    public function test_cancel_only_latest_receive(): void
    {
        $account = $this->openAccount();
        $first = $this->service->receive($account, '500', null, null, $this->admin);
        $second = $this->service->receive($account, '100', null, null, $this->admin);

        try {
            $this->service->cancelTransaction($first, $this->admin, 'Too old');
            $this->fail('Cancelling a non-latest transaction should be blocked.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->service->cancelTransaction($second, $this->admin, 'Duplicate entry');
        $this->assertSame('500.00', (string) $account->fresh()->current_balance);
        $this->assertNotNull($second->fresh()->cancel_at);
    }

    public function test_share_receive_and_payment_update_member_share_amount(): void
    {
        $member = Member::query()->firstOrFail();
        $member->update(['share_amount' => 0]);

        $this->service->shareReceive($member, '500', null, null, $this->admin);
        $this->assertSame('500.00', (string) $member->fresh()->share_amount);
        $this->assertDatabaseHas('account_transactions', [
            'account_type' => 'share', 'account_id' => $member->id, 'tran_code' => 'SHARE_RECEIVE',
        ]);
        // No deposit account was created for the share movement.
        $this->assertSame(0, DepositAccount::where('member_id', $member->id)->count());

        $this->service->sharePayment($member->fresh(), '200', null, null, $this->admin);
        $this->assertSame('300.00', (string) $member->fresh()->share_amount);

        try {
            $this->service->sharePayment($member->fresh(), '301', null, null, $this->admin);
            $this->fail('Share overpayment should be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_http_receive_and_pages_render(): void
    {
        $account = $this->openAccount();
        $member = $account->member;

        $this->actingAs($this->admin)->post('/deposits/receive', [
            'account_id' => $account->id, 'amount' => '750', 'document_number' => 'R-9',
        ])->assertRedirect();
        $this->assertSame('750.00', (string) $account->fresh()->current_balance);
        $this->assertSame(1, DepositTransaction::where('account_id', $account->id)->count());

        $this->actingAs($this->admin)->get('/deposits/receive?member='.$member->member_no.'&account_id='.$account->id)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/Receive')->has('history', 1));
        $this->actingAs($this->admin)->get('/deposits/payment?member='.$member->member_no)->assertOk();
        $this->actingAs($this->admin)->get('/deposits/transfer')->assertOk();
        $this->actingAs($this->admin)->get('/deposits/share?member='.$member->member_no)
            ->assertOk()->assertInertia(fn ($p) => $p->component('Deposits/Share'));
    }

    public function test_fdr_receive_uses_fdr_gl_control(): void
    {
        $account = $this->openAccount('FDR');
        $this->service->receive($account, '5000', null, null, $this->admin);
        $this->assertSame('5000.00', app(PostingService::class)->ledgerBalance(Ledger::byCode('2030'), $account->branch_id));
        $detail = DepositTransaction::where('account_id', $account->id)->firstOrFail();
        $this->assertSame('FDR', $detail->product);
    }
}
