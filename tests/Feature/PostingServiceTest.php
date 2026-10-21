<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\Branch;
use App\Models\BusinessDay;
use App\Models\CashLedger;
use App\Models\Ledger;
use App\Models\User;
use App\Models\Voucher;
use App\Services\BusinessDayService;
use App\Services\PostingService;
use App\Services\ReconcileService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan Step 3A invariant tests (G8): balanced-only posting, idempotency,
 * lock serialization on sub-ledger rows, SoD authorization, break-glass,
 * control reconciliation, business-day enforcement.
 */
class PostingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PostingService $posting;
    private Branch $branch;
    private User $cashier;
    private User $manager;

    /** @var array<int, int> fixture number => real deposit_accounts.id */
    private array $depositAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(PostingService::class);
        $this->branch = Branch::where('code', '102')->first();
        $this->cashier = User::factory()->create();
        $this->manager = User::factory()->create();
    }

    /**
     * Since Step 10 the voucher_lines.deposit_account_id FK is enforced, so
     * the old bare fixture ids (9001…) now resolve to real deposit accounts
     * created on the seeded member/product.
     */
    private function depositAccountId(int $fixture = 9001): int
    {
        if (! isset($this->depositAccounts[$fixture])) {
            $member = \App\Models\Member::query()->firstOrFail();
            $account = \App\Models\DepositAccount::create([
                'account_number' => 'TEST-'.$fixture,
                'branch_id' => $member->branch_id,
                'member_id' => $member->id,
                'deposit_product_id' => \App\Models\DepositProduct::where('code', 'SAVINGS')->value('id'),
                'opening_date' => '2026-01-01',
                'profit_rate' => 0,
            ]);
            $this->depositAccounts[$fixture] = $account->id;
        }

        return $this->depositAccounts[$fixture];
    }

    private function savingsReceipt(string $amount = '500.00', int $accountId = 9001, array $extra = []): Voucher
    {
        $accountId = $this->depositAccountId($accountId);
        return $this->posting->post(array_merge([
            'branch_id' => $this->branch->id,
            'voucher_type' => 'Receipt',
            'entry_by' => $this->cashier->id,
            'lines' => [['mapping_key' => 'deposit.savings.receive', 'amount' => $amount, 'deposit_account_id' => $accountId]],
            'movements' => [
                ['account_type' => 'deposit', 'account_id' => $accountId, 'tran_code' => 'RECEIVE', 'amount' => $amount],
                ['account_type' => 'cash', 'account_id' => $this->cashier->id, 'tran_code' => 'CASH_IN', 'amount' => $amount],
            ],
        ], $extra));
    }

    public function test_balanced_posting_writes_voucher_lines_subledger_and_cached_cash(): void
    {
        $voucher = $this->savingsReceipt('500.00');

        // Small receipt: auto-authorized at posting (binding decision).
        $this->assertSame('Authorized', $voucher->status);
        $this->assertSame('500.00', (string) $voucher->total_amount);
        $this->assertSame(2, $voucher->lines()->count());

        $deposit = AccountTransaction::where('account_type', 'deposit')->where('account_id', $this->depositAccountId(9001))->first();
        $this->assertSame('500.00', (string) $deposit->amount);
        $this->assertSame('500.00', (string) $deposit->balance_after);

        $cash = CashLedger::where('user_id', $this->cashier->id)->first();
        $this->assertSame('500.00', (string) $cash->current_balance);

        // GL: Cash in Hand Dr 500; Member Savings Control Cr-normal balance 500.
        $this->assertSame('500.00', $this->posting->ledgerBalance(Ledger::byCode('1010')));
        $this->assertSame('500.00', $this->posting->ledgerBalance(Ledger::byCode('2010')));

        $tb = $this->posting->trialBalance($this->branch->id);
        $this->assertTrue($tb['balanced']);
    }

    public function test_running_balances_accumulate_per_account(): void
    {
        $this->savingsReceipt('500.00');
        $this->savingsReceipt('250.50');
        $rows = AccountTransaction::where('account_type', 'deposit')->where('account_id', $this->depositAccountId(9001))->orderBy('id')->get();
        $this->assertSame('750.50', (string) $rows->last()->balance_after);
        $this->assertSame('750.50', (string) CashLedger::where('user_id', $this->cashier->id)->first()->current_balance);
    }

    public function test_unbalanced_voucher_is_rejected_and_nothing_persists(): void
    {
        $cash = Ledger::byCode('1010');
        $savings = Ledger::byCode('2010');
        try {
            $this->posting->post([
                'branch_id' => $this->branch->id, 'voucher_type' => 'Journal', 'entry_by' => $this->cashier->id,
                'lines' => [
                    ['ledger_id' => $cash->id, 'debit' => '100.00', 'credit' => '0'],
                    ['ledger_id' => $savings->id, 'debit' => '0', 'credit' => '90.00'],
                ],
            ]);
            $this->fail('Unbalanced voucher was posted');
        } catch (DomainException) {
            // Rejected as unbalanced (message is localized).
        }
        $this->assertSame(0, Voucher::count());
    }

    public function test_double_post_with_same_batch_number_is_a_noop(): void
    {
        $first = $this->savingsReceipt('500.00', 9001, ['batch_number' => 'IDEMPOTENT-1']);
        $countAfterFirst = AccountTransaction::count();

        $second = $this->savingsReceipt('500.00', 9001, ['batch_number' => 'IDEMPOTENT-1']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($countAfterFirst, AccountTransaction::count());
        $this->assertSame(1, Voucher::where('batch_number', 'IDEMPOTENT-1')->count());
    }

    public function test_subledger_unique_index_blocks_duplicate_movement_for_same_voucher(): void
    {
        $voucher = $this->savingsReceipt('100.00');
        $this->expectException(QueryException::class);
        AccountTransaction::create([
            'branch_id' => $this->branch->id, 'account_type' => 'deposit', 'account_id' => $this->depositAccountId(9001),
            'tran_code' => 'RECEIVE', 'amount' => '100.00', 'balance_after' => '200.00',
            'tran_date' => now()->toDateString(), 'business_date' => now()->toDateString(),
            'voucher_id' => $voucher->id, 'entry_by' => $this->cashier->id, 'entry_at' => now(),
        ]);
    }

    public function test_large_receipt_requires_authorization_and_sod_applies(): void
    {
        // >= threshold (50000) needs authorization even for Receipt type.
        $voucher = $this->savingsReceipt('60000.00', 9002);
        $this->assertSame('Pending', $voucher->status);

        // Maker cannot authorize their own voucher.
        try {
            $this->posting->authorize($voucher, $this->cashier);
            $this->fail('SoD violation allowed');
        } catch (DomainException) {
        }
        $this->assertSame('Pending', $voucher->fresh()->status);

        $authorized = $this->posting->authorize($voucher, $this->manager);
        $this->assertSame('Authorized', $authorized->status);
        $this->assertSame($this->manager->id, $authorized->auth_by);
    }

    public function test_breakglass_force_authorize_needs_permission_and_reason(): void
    {
        $voucher = $this->savingsReceipt('60000.00', 9003);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        try {
            $this->posting->authorize($voucher, $admin, breakGlass: true, reason: null);
            $this->fail('Break-glass without reason allowed');
        } catch (DomainException) {
        }

        // The maker themselves may break-glass (audited), unlike normal auth.
        $maker = $this->cashier;
        $maker->assignRole('super-admin');
        $done = $this->posting->authorize($voucher, $maker, breakGlass: true, reason: 'Manager unavailable; festival rush');
        $this->assertSame('Authorized', $done->status);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'voucher.breakglass_authorized')->first());
    }

    public function test_posting_to_a_closed_business_date_is_rejected(): void
    {
        $days = app(BusinessDayService::class);
        $today = $days->currentDate($this->branch->id);
        BusinessDay::where('branch_id', $this->branch->id)->where('business_date', $today)->update(['is_closed' => true]);

        $this->expectException(DomainException::class);
        $this->posting->post([
            'branch_id' => $this->branch->id, 'voucher_type' => 'Receipt', 'entry_by' => $this->cashier->id,
            'transaction_date' => $today,
            'lines' => [['mapping_key' => 'deposit.savings.receive', 'amount' => '10.00']],
        ]);
    }

    public function test_row_lock_serializes_concurrent_cash_postings(): void
    {
        // Two connections: the second cannot take the cash row lock (NOWAIT)
        // while the first transaction holds it — the primitive PostingService
        // relies on instead of locking GL ledgers.
        $this->savingsReceipt('100.00');
        $cashId = CashLedger::where('user_id', $this->cashier->id)->first()->id;

        DB::beginTransaction();
        DB::select("SELECT id FROM cash_ledgers WHERE id = {$cashId} FOR UPDATE");

        // A genuinely separate connection (own PDO), not the cached default.
        config(['database.connections.mysql_locktest' => config('database.connections.mysql')]);
        $second = DB::connection('mysql_locktest');
        $lockFailed = false;
        try {
            $second->select("SELECT id FROM cash_ledgers WHERE id = {$cashId} FOR UPDATE NOWAIT");
        } catch (QueryException) {
            $lockFailed = true;
        }
        $second->disconnect();
        DB::rollBack();

        $this->assertTrue($lockFailed, 'Second connection acquired the cash row lock concurrently');
    }

    public function test_cancel_reverses_cached_cash_and_flags_rows(): void
    {
        $voucher = $this->savingsReceipt('200.00');
        $this->posting->cancel($voucher, $this->manager, 'Wrong member');

        $this->assertSame('Cancelled', $voucher->fresh()->status);
        $this->assertSame('0.00', (string) CashLedger::where('user_id', $this->cashier->id)->first()->current_balance);
        $this->assertSame(2, AccountTransaction::where('voucher_id', $voucher->id)->whereNotNull('cancel_at')->count());
    }

    public function test_reconciliation_is_clean_after_postings(): void
    {
        $this->savingsReceipt('500.00');
        $this->savingsReceipt('125.75', 9005);
        $this->assertSame([], app(ReconcileService::class)->run());
    }

    public function test_no_ledger_codes_are_hard_coded_in_services(): void
    {
        // Structural guard: posting paths resolve ledgers via gl_mappings.
        $posting = file_get_contents(app_path('Services/PostingService.php'));
        $this->assertStringNotContainsString("byCode('", $posting);
        $this->assertStringNotContainsString('ledger_code', $posting);
    }
}
