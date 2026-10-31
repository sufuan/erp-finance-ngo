<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashCount;
use App\Models\CashLedger;
use App\Models\CashTransfer;
use App\Models\FundTransferRequest;
use App\Models\GroupTransaction;
use App\Models\Ledger;
use App\Models\LedgerGroup;
use App\Models\RecurringSetup;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AccountReportService;
use App\Services\AccountsService;
use App\Services\BusinessDayService;
use App\Services\PostingService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Crud\CrudTestCase;

/**
 * Module 9 (Accounts screens, Steps 26–30): real-form HTTP flows,
 * figure reconciliation against raw journal lines, branch scoping,
 * inter-branch + cash handover lifecycles.
 */
class AccountsModuleTest extends CrudTestCase
{
    private Branch $b102;
    private Branch $b101;

    protected function setUp(): void
    {
        parent::setUp();
        $this->b102 = $this->branch('102');
        $this->b101 = $this->branch('101');
        $this->post('/branch/switch', ['branch_id' => $this->b102->id])->assertRedirect();
    }

    private function ledger(string $code): Ledger
    {
        return Ledger::where('ledger_code', $code)->firstOrFail();
    }

    private function biz(int $branchId): string
    {
        return app(BusinessDayService::class)->currentDate($branchId);
    }

    private function gl(string $code, int $branchId): string
    {
        return app(PostingService::class)->ledgerBalance($this->ledger($code), $branchId);
    }

    private function officer(string $role = 'accountant', ?Branch $branch = null): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole($role);
        $user->branches()->attach(($branch ?? $this->b102)->id);

        return $user;
    }

    private function actAs(User $user, Branch $branch): void
    {
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->post('/branch/switch', ['branch_id' => $branch->id])->assertRedirect();
    }

    // -- Financial transactions ------------------------------------------------

    public function test_account_transaction_receive_posts_receipt_and_moves_cash(): void
    {
        $cashBefore = $this->gl('1010', $this->b102->id);
        $incomeBefore = $this->gl('4030', $this->b102->id);

        $this->post('/accounts/transaction', [
            'side' => 'Receive', 'ledger_id' => $this->ledger('4030')->id, 'source' => 'cash',
            'amount' => '1000', 'comments' => 'Admission fees',
        ])->assertRedirect()->assertSessionHas('success');

        $voucher = Voucher::where('branch_id', $this->b102->id)->where('voucher_type', 'Receipt')->latest('id')->firstOrFail();
        $this->assertSame('Authorized', $voucher->status); // receipts auto-authorize
        $this->assertSame('1000.00', (string) $voucher->total_amount);
        $this->assertSame(bcadd($cashBefore, '1000', 2), $this->gl('1010', $this->b102->id));
        $this->assertSame(bcadd($incomeBefore, '1000', 2), $this->gl('4030', $this->b102->id));
        // The posting officer's cash sub-ledger received the money.
        $cash = CashLedger::where('user_id', $this->admin->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $cash->current_balance);
    }

    public function test_payment_is_pending_and_maker_cannot_authorize_own_voucher(): void
    {
        $officer = $this->officer();
        $this->actAs($officer, $this->b102);
        $this->post('/accounts/transaction', [
            'side' => 'Payment', 'ledger_id' => $this->ledger('5010')->id, 'source' => 'cash',
            'amount' => '1200', 'comments' => 'Salary advance',
        ])->assertRedirect()->assertSessionHas('success');
        $voucher = Voucher::where('voucher_type', 'Payment')->latest('id')->firstOrFail();
        $this->assertSame('Pending', $voucher->status);

        // Maker-checker: the poster cannot authorize their own voucher.
        $this->post("/accounts/vouchers/{$voucher->id}/authorize")->assertSessionHas('error');
        $this->assertSame('Pending', $voucher->fresh()->status);

        // A different authorized user can.
        $this->actAs($this->admin, $this->b102);
        $this->post("/accounts/vouchers/{$voucher->id}/authorize")->assertSessionHas('success');
        $this->assertSame('Authorized', $voucher->fresh()->status);
    }

    public function test_journal_cancel_restores_balances_exactly(): void
    {
        $beforeE = $this->gl('5040', $this->b102->id);
        $beforeL = $this->gl('2060', $this->b102->id);

        $officer = $this->officer();
        $this->actAs($officer, $this->b102);
        $this->post('/accounts/journal', [
            'lines' => [
                ['ledger_id' => $this->ledger('5040')->id, 'debit' => '800', 'credit' => ''],
                ['ledger_id' => $this->ledger('2060')->id, 'debit' => '', 'credit' => '800'],
            ],
            'comments' => 'Accrued training cost',
        ])->assertRedirect()->assertSessionHas('success');
        $voucher = Voucher::where('voucher_type', 'Journal')->latest('id')->firstOrFail();

        $this->actAs($this->admin, $this->b102);
        $this->post("/accounts/vouchers/{$voucher->id}/authorize")->assertSessionHas('success');
        $this->assertSame(bcadd($beforeE, '800', 2), $this->gl('5040', $this->b102->id));
        $this->assertSame(bcadd($beforeL, '800', 2), $this->gl('2060', $this->b102->id));

        $this->post("/accounts/vouchers/{$voucher->id}/cancel", ['reason' => 'Wrong head'])
            ->assertSessionHas('success');
        $this->assertSame('Cancelled', $voucher->fresh()->status);
        $this->assertSame($beforeE, $this->gl('5040', $this->b102->id));
        $this->assertSame($beforeL, $this->gl('2060', $this->b102->id));
    }

    public function test_contra_and_ledger_transaction_move_exact_amounts(): void
    {
        $officer = $this->officer();
        $this->actAs($officer, $this->b102);
        // Seed the officer's cash via a receipt first.
        $this->post('/accounts/transaction', [
            'side' => 'Receive', 'ledger_id' => $this->ledger('4030')->id, 'source' => 'cash', 'amount' => '2000',
        ])->assertRedirect()->assertSessionHas('success');

        $cashBefore = $this->gl('1010', $this->b102->id);
        $bankBefore = $this->gl('1020', $this->b102->id);
        $this->post('/accounts/contra', [
            'direction' => 'cash_to_bank', 'amount' => '500', 'comments' => 'Bank deposit',
        ])->assertRedirect()->assertSessionHas('success');
        $contra = Voucher::where('voucher_type', 'Contra')->latest('id')->firstOrFail();
        $this->actAs($this->admin, $this->b102);
        $this->post("/accounts/vouchers/{$contra->id}/authorize")->assertSessionHas('success');
        $this->assertSame(bcsub($cashBefore, '500', 2), $this->gl('1010', $this->b102->id));
        $this->assertSame(bcadd($bankBefore, '500', 2), $this->gl('1020', $this->b102->id));

        // Ledger transaction: debit an expense, credit retained earnings.
        $reBefore = $this->gl('3020', $this->b102->id);
        $expBefore = $this->gl('5070', $this->b102->id);
        $this->actAs($officer, $this->b102);
        $this->post('/accounts/ledger-transaction', [
            'debit_ledger_id' => $this->ledger('5070')->id, 'credit_ledger_id' => $this->ledger('3020')->id, 'amount' => '750',
        ])->assertRedirect()->assertSessionHas('success');
        $lt = Voucher::where('voucher_type', 'Journal')->latest('id')->firstOrFail();
        $this->actAs($this->admin, $this->b102);
        $this->post("/accounts/vouchers/{$lt->id}/authorize")->assertSessionHas('success');
        $this->assertSame(bcadd($expBefore, '750', 2), $this->gl('5070', $this->b102->id));
        $this->assertSame(bcadd($reBefore, '750', 2), $this->gl('3020', $this->b102->id)); // credit-normal
    }

    public function test_control_ledger_is_rejected_for_manual_posting(): void
    {
        $this->post('/accounts/transaction', [
            'side' => 'Receive', 'ledger_id' => $this->ledger('2010')->id, 'source' => 'cash', 'amount' => '100',
        ])->assertSessionHasErrors('ledger_id');
        $this->assertSame(0, Voucher::where('voucher_type', 'Receipt')->count());
    }

    // -- Reconciliation -----------------------------------------------------------

    public function test_statements_reconcile_with_raw_journal_lines(): void
    {
        $reports = app(AccountReportService::class);
        $officer = $this->officer();

        // Curated scenario on branch 102.
        $this->actAs($officer, $this->b102);
        $this->post('/accounts/transaction', ['side' => 'Receive', 'ledger_id' => $this->ledger('4030')->id, 'source' => 'cash', 'amount' => '3000'])->assertSessionHas('success');
        $this->post('/accounts/transaction', ['side' => 'Payment', 'ledger_id' => $this->ledger('5010')->id, 'source' => 'bank', 'amount' => '1200'])->assertSessionHas('success');
        $payment = Voucher::where('voucher_type', 'Payment')->latest('id')->firstOrFail();
        $this->post('/accounts/journal', ['lines' => [
            ['ledger_id' => $this->ledger('5040')->id, 'debit' => '800', 'credit' => ''],
            ['ledger_id' => $this->ledger('2060')->id, 'debit' => '', 'credit' => '800'],
        ]])->assertSessionHas('success');
        $journal = Voucher::where('voucher_type', 'Journal')->latest('id')->firstOrFail();
        $this->post('/accounts/contra', ['direction' => 'cash_to_bank', 'amount' => '500'])->assertSessionHas('success');
        $contra = Voucher::where('voucher_type', 'Contra')->latest('id')->firstOrFail();

        $this->actAs($this->admin, $this->b102);
        foreach ([$payment, $journal, $contra] as $v) {
            $this->post("/accounts/vouchers/{$v->id}/authorize")->assertSessionHas('success');
        }

        $to = $this->biz($this->b102->id);
        $from = $reports->fiscalYearStart($this->b102->id, $to);
        $ids = [$this->b102->id];
        $norm = fn ($x) => bccomp((string) $x, '0', 2) === 0 ? '0.00' : bcadd((string) $x, '0', 2);

        // 1) Trial balance is balanced and equals raw journal-line sums.
        $tb = $reports->trialBalance($ids, $from, $to);
        $this->assertTrue($tb['totals']['balanced']);
        $this->assertSame($tb['totals']['closing_debit'], $tb['totals']['closing_credit']);
        $raw = DB::table('voucher_lines')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_lines.voucher_id')
            ->where('vouchers.branch_id', $this->b102->id)
            ->where('vouchers.status', '!=', 'Cancelled')
            ->groupBy('voucher_lines.ledger_id')
            ->selectRaw('voucher_lines.ledger_id, SUM(voucher_lines.debit) as d, SUM(voucher_lines.credit) as c')
            ->get()->keyBy('ledger_id');
        foreach ($tb['rows'] as $row) {
            $r = $raw[$row['ledger_id']] ?? null;
            $expectedSigned = $r ? bcsub((string) $r->d, (string) $r->c, 2) : '0.00';
            $signed = in_array($row['group'], ['Liability', 'Equity', 'Income'], true)
                ? bcmul($expectedSigned, '-1', 2) : $expectedSigned;
            $this->assertSame($norm($signed), $norm($row['closing']), "TB balance mismatch for ledger {$row['code']}");
            // 2) Ledger statement closing ties to the trial balance.
            $stmt = $reports->ledgerStatement($ids, Ledger::find($row['ledger_id']), $from, $to);
            $this->assertSame($norm($row['closing']), $norm($stmt['closing']), "Statement closing mismatch for {$row['code']}");
        }

        // 3) Balance sheet equation holds.
        $bs = $reports->balanceSheet($ids, $to);
        $this->assertTrue($bs['balanced']);
        $this->assertSame($bs['total_assets'], $bs['total_liab_equity']);

        // 4) Receipt & Payment ties to cash+bank movements.
        $rp = $reports->receiptPayment($ids, $from, $to);
        $cashBank = bcadd(
            $reports->balanceUpTo($ids, $this->ledger('1010'), $to),
            $reports->balanceUpTo($ids, $this->ledger('1020'), $to),
            2
        );
        $this->assertSame($norm($cashBank), $norm($rp['closing']));
        $this->assertSame(
            $norm(bcsub(bcadd($rp['opening'], $rp['total_receipts'], 2), $rp['total_payments'], 2)),
            $norm($rp['closing'])
        );
        $this->assertGreaterThanOrEqual(3000, (float) $rp['total_receipts']);
    }

    // -- Account management --------------------------------------------------------

    public function test_new_ledger_opening_with_opening_balance(): void
    {
        $this->post('/accounts/ledgers', [
            'ledger_code' => '5099', 'name_en' => 'Reconciliation Test Expense', 'ledger_group' => 'Expense', 'opening_balance' => '2500',
        ])->assertRedirect()->assertSessionHas('success');
        $ledger = $this->ledger('5099');
        $this->assertSame('2500.00', app(PostingService::class)->ledgerBalance($ledger, $this->b102->id));
        $this->assertSame('2500.00', (string) Voucher::where('voucher_type', 'Journal')->latest('id')->firstOrFail()->total_amount);
    }

    public function test_group_transaction_posts_balanced_journal(): void
    {
        $this->post('/accounts/groups', [
            'name' => 'Monthly Office Costs',
            'lines' => [
                ['ledger_id' => $this->ledger('5040')->id, 'side' => 'Debit'],
                ['ledger_id' => $this->ledger('4040')->id, 'side' => 'Credit'],
            ],
        ])->assertRedirect()->assertSessionHas('success');
        $group = LedgerGroup::where('name', 'Monthly Office Costs')->firstOrFail();
        $amounts = $group->lines->mapWithKeys(fn ($l) => [$l->id => '1000'])->all();

        $this->post('/accounts/group-transaction', ['group_id' => $group->id, 'amounts' => $amounts, 'comments' => 'October costs'])
            ->assertRedirect()->assertSessionHas('success');
        $tx = GroupTransaction::where('group_id', $group->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $tx->voucher->total_amount);
        $this->assertSame('Pending', $tx->voucher->status);
    }

    public function test_recurring_posting_is_idempotent_per_run(): void
    {
        $service = app(AccountsService::class);
        $setup = RecurringSetup::create([
            'branch_id' => $this->b102->id, 'name' => 'Office Rent Accrual',
            'debit_ledger_id' => $this->ledger('5020')->id, 'credit_ledger_id' => $this->ledger('2060')->id,
            'amount' => '700', 'frequency' => 'Monthly',
            'next_run_date' => $this->biz($this->b102->id), 'entry_by' => $this->admin->id,
        ]);
        $voucher = $service->postRecurring($setup, $this->admin);
        $this->assertSame('700.00', (string) $voucher->total_amount);
        $this->assertNotSame($this->biz($this->b102->id), $setup->fresh()->next_run_date->toDateString()); // advanced

        // A retry of the SAME run (next_run_date reset, e.g. a re-submit
        // race) must not double-post: the batch number is the key.
        $setup->update(['next_run_date' => $this->biz($this->b102->id)]);
        $again = $service->postRecurring($setup->fresh(), $this->admin);
        $this->assertSame($voucher->id, $again->id);
        $this->assertSame(1, Voucher::where('batch_number', 'REC-'.$setup->id.'-'.$this->biz($this->b102->id))->count());
    }

    // -- Inter-branch ----------------------------------------------------------------

    public function test_inter_branch_fund_flow_and_clearing_nets_to_zero(): void
    {
        // Give branch 102 spendable GL cash first (receipt as admin).
        $this->post('/accounts/transaction', ['side' => 'Receive', 'ledger_id' => $this->ledger('4030')->id, 'source' => 'cash', 'amount' => '9000'])->assertSessionHas('success');

        $this->post('/accounts/transfer-request', [
            'kind' => 'Fund', 'to_branch_id' => $this->b101->id, 'amount' => '5000', 'comments' => 'HO support',
        ])->assertRedirect()->assertSessionHas('success');
        $request = FundTransferRequest::latest('id')->firstOrFail();
        $this->assertSame('Pending', $request->status);

        $sumBefore = bcadd($this->gl('1130', $this->b101->id), $this->gl('1130', $this->b102->id), 2);
        $this->post("/accounts/transfers/{$request->id}/approve")->assertSessionHas('success');
        $this->assertSame('Approved', $request->fresh()->status);
        $this->assertSame('5000.00', $this->gl('1130', $this->b102->id));

        // The SENDING branch's scoped user cannot receive it (403).
        $manager102 = $this->officer('branch-manager', $this->b102);
        $this->actAs($manager102, $this->b102);
        $this->post("/accounts/transfers/{$request->id}/receive")->assertForbidden();
        $this->assertSame('Approved', $request->fresh()->status);

        // The RECEIVING branch's user can.
        $manager101 = $this->officer('branch-manager', $this->b101);
        $this->actAs($manager101, $this->b101);
        $this->post("/accounts/transfers/{$request->id}/receive")->assertSessionHas('success');
        $this->assertSame('Received', $request->fresh()->status);
        $sumAfter = bcadd($this->gl('1130', $this->b101->id), $this->gl('1130', $this->b102->id), 2);
        $this->assertSame($sumBefore, $sumAfter); // clearing nets out across branches
    }

    public function test_product_request_zero_value_posts_no_voucher(): void
    {
        $vouchersBefore = Voucher::count();
        $this->post('/accounts/transfer-request', [
            'kind' => 'Product', 'to_branch_id' => $this->b101->id, 'item_name' => 'Passbook bundle', 'quantity' => '50', 'amount' => '',
        ])->assertRedirect()->assertSessionHas('success');
        $request = FundTransferRequest::latest('id')->firstOrFail();
        $this->post("/accounts/transfers/{$request->id}/approve")->assertSessionHas('success');
        $manager101 = $this->officer('branch-manager', $this->b101);
        $this->actAs($manager101, $this->b101);
        $this->post("/accounts/transfers/{$request->id}/receive")->assertSessionHas('success');
        $this->assertSame('Received', $request->fresh()->status);
        $this->assertSame($vouchersBefore, Voucher::count()); // logistics only
    }

    // -- Cash management ---------------------------------------------------------------

    public function test_cash_handover_with_shortage_posts_variance(): void
    {
        $officer = $this->officer();
        $this->actAs($officer, $this->b102);
        $this->post('/accounts/transaction', ['side' => 'Receive', 'ledger_id' => $this->ledger('4030')->id, 'source' => 'cash', 'amount' => '2000'])->assertSessionHas('success');

        $glBefore = $this->gl('1010', $this->b102->id);
        $this->post('/accounts/cash', ['from_user_id' => $officer->id, 'to_user_id' => $this->admin->id, 'amount' => '1200'])
            ->assertRedirect()->assertSessionHas('success');
        $transfer = CashTransfer::latest('id')->firstOrFail();
        $this->assertSame('Pending', $transfer->status);

        $this->actAs($this->admin, $this->b102);
        $this->post("/accounts/cash/{$transfer->id}/receive", ['counted_amount' => '1150'])
            ->assertSessionHas('success');
        $transfer->refresh();
        $this->assertSame('Received', $transfer->status);
        $this->assertSame('1150.00', (string) $transfer->counted_amount);
        $this->assertNotNull($transfer->variance_voucher_id); // shortage booked

        $officerCash = CashLedger::where('user_id', $officer->id)->firstOrFail();
        $this->assertSame('800.00', (string) $officerCash->current_balance); // handed over the full 1200
        $adminCash = CashLedger::where('user_id', $this->admin->id)->firstOrFail();
        $this->assertSame('1150.00', (string) $adminCash->current_balance); // counted amount only
        // GL cash fell by exactly the shortage.
        $this->assertSame(bcsub($glBefore, '50', 2), $this->gl('1010', $this->b102->id));
        // Day-end count records expected vs counted.
        $this->post('/accounts/cash/count', ['user_id' => $this->admin->id, 'counted_balance' => '1150'])->assertSessionHas('success');
        $count = CashCount::where('user_id', $this->admin->id)->firstOrFail();
        $this->assertSame('1150.00', (string) $count->expected_balance);
        $this->assertSame('0.00', (string) $count->difference);
    }

    public function test_cash_handover_rejects_amount_above_officer_balance(): void
    {
        $officer = $this->officer();
        $this->actAs($officer, $this->b102);
        $this->post('/accounts/cash', ['from_user_id' => $officer->id, 'to_user_id' => $this->admin->id, 'amount' => '500'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, CashTransfer::count());
    }

    // -- Branch scoping -------------------------------------------------------------------

    public function test_branch_scoping_hides_foreign_vouchers_and_reports(): void
    {
        // A voucher in branch 101.
        $this->actAs($this->admin, $this->b101);
        $this->post('/accounts/transaction', ['side' => 'Receive', 'ledger_id' => $this->ledger('4030')->id, 'source' => 'cash', 'amount' => '777'])->assertSessionHas('success');
        $foreign = Voucher::where('branch_id', $this->b101->id)->latest('id')->firstOrFail();

        $manager102 = $this->officer('branch-manager', $this->b102);
        $this->actAs($manager102, $this->b102);
        $this->get("/accounts/vouchers/{$foreign->id}")->assertForbidden();
        $this->get('/accounts/reports?report=trial_balance&run=1&branch_id='.$this->b101->id)->assertForbidden();
        $this->get('/accounts/vouchers')->assertOk()
            ->assertInertia(fn ($page) => $page->where('vouchers', fn ($v) => collect($v)->every(fn ($row) => $row['id'] !== $foreign->id)));
        // Cannot cancel a foreign voucher either.
        $this->post("/accounts/vouchers/{$foreign->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_user_without_accounts_permission_is_forbidden(): void
    {
        $plain = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($plain);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->get('/accounts/vouchers')->assertForbidden();
        $this->get('/accounts/transaction')->assertForbidden();
    }

    public function test_masters_recurring_and_register_crud_paths(): void
    {
        // Billing types: create → update → deactivate.
        $this->post('/accounts/masters/billing-types', ['name_en' => 'Gate Fee', 'name_bn' => 'গেট ফি'])
            ->assertSessionHas('success');
        $billing = \App\Models\BillingType::where('name_en', 'Gate Fee')->firstOrFail();
        $this->put("/accounts/masters/billing-types/{$billing->id}", ['name_en' => 'Gate Fee II', 'name_bn' => 'গেট ফি', 'is_active' => true])
            ->assertSessionHas('success');
        $this->assertSame('Gate Fee II', $billing->fresh()->name_en);
        $this->delete("/accounts/masters/billing-types/{$billing->id}")->assertSessionHas('success');
        $this->assertFalse($billing->fresh()->is_active);

        // Receive/Payment setups: create → update.
        $this->post('/accounts/masters/setups', [
            'name' => 'Gate Salary', 'ledger_id' => $this->ledger('5010')->id, 'side' => 'Payment',
        ])->assertSessionHas('success');
        $setup = \App\Models\ReceivePaymentSetup::where('name', 'Gate Salary')->firstOrFail();
        $this->put("/accounts/masters/setups/{$setup->id}", [
            'name' => 'Gate Salary', 'ledger_id' => $this->ledger('5020')->id, 'side' => 'Payment', 'is_active' => true,
        ])->assertSessionHas('success');
        $this->assertSame($this->ledger('5020')->id, $setup->fresh()->ledger_id);

        // Recurring via HTTP: create → post endpoint → voucher exists; update renames.
        $this->post('/accounts/recurring', [
            'name' => 'Gate Rent', 'debit_ledger_id' => $this->ledger('5050')->id,
            'credit_ledger_id' => $this->ledger('2060')->id, 'amount' => '900',
            'frequency' => 'Monthly', 'next_run_date' => $this->biz($this->b102->id),
        ])->assertSessionHas('success');
        $recurring = RecurringSetup::where('name', 'Gate Rent')->firstOrFail();
        $this->post("/accounts/recurring/{$recurring->id}/post")->assertSessionHas('success');
        $this->assertNotNull($recurring->fresh()->last_voucher_id);
        $this->put("/accounts/recurring/{$recurring->id}", [
            'name' => 'Gate Rent (revised)', 'debit_ledger_id' => $this->ledger('5050')->id,
            'credit_ledger_id' => $this->ledger('2060')->id, 'amount' => '950',
            'frequency' => 'Monthly', 'next_run_date' => $recurring->fresh()->next_run_date->toDateString(), 'is_active' => true,
        ])->assertSessionHas('success');
        $this->assertSame('Gate Rent (revised)', $recurring->fresh()->name);

        // Ledger update (rename + deactivate) via HTTP.
        $this->post('/accounts/ledgers', ['ledger_code' => '5098', 'name_en' => 'Gate Ledger', 'ledger_group' => 'Expense'])
            ->assertSessionHas('success');
        $gate = $this->ledger('5098');
        $this->put("/accounts/ledgers/{$gate->id}", ['name_en' => 'Gate Ledger Renamed', 'is_active' => false])
            ->assertSessionHas('success');
        $this->assertSame('Gate Ledger Renamed', $gate->fresh()->name_en);
        $this->assertFalse($gate->fresh()->is_active);

        // Group update replaces lines; deactivate soft-disables.
        $this->post('/accounts/groups', ['name' => 'Gate Group', 'lines' => [
            ['ledger_id' => $this->ledger('5040')->id, 'side' => 'Debit'],
            ['ledger_id' => $this->ledger('4040')->id, 'side' => 'Credit'],
        ]])->assertSessionHas('success');
        $group = LedgerGroup::where('name', 'Gate Group')->firstOrFail();
        $this->put("/accounts/groups/{$group->id}", ['name' => 'Gate Group', 'is_active' => true, 'lines' => [
            ['ledger_id' => $this->ledger('5050')->id, 'side' => 'Debit'],
            ['ledger_id' => $this->ledger('4040')->id, 'side' => 'Credit'],
        ]])->assertSessionHas('success');
        $this->assertSame($this->ledger('5050')->id, $group->fresh()->lines->first()->ledger_id);
        $this->delete("/accounts/groups/{$group->id}")->assertSessionHas('success');
        $this->assertFalse($group->fresh()->is_active);

        // Transfer reject path + cash cancel path.
        $this->post('/accounts/transfer-request', ['kind' => 'Fund', 'to_branch_id' => $this->b101->id, 'amount' => '100'])
            ->assertSessionHas('success');
        $request = FundTransferRequest::latest('id')->firstOrFail();
        $this->post("/accounts/transfers/{$request->id}/close", ['to' => 'Rejected'])->assertSessionHas('success');
        $this->assertSame('Rejected', $request->fresh()->status);

        $officer = $this->officer();
        $this->actAs($officer, $this->b102);
        $this->post('/accounts/transaction', ['side' => 'Receive', 'ledger_id' => $this->ledger('4030')->id, 'source' => 'cash', 'amount' => '300'])->assertSessionHas('success');
        $this->post('/accounts/cash', ['from_user_id' => $officer->id, 'to_user_id' => $this->admin->id, 'amount' => '100'])
            ->assertSessionHas('success');
        $cashTransfer = CashTransfer::latest('id')->firstOrFail();
        $this->post("/accounts/cash/{$cashTransfer->id}/cancel")->assertSessionHas('success');
        $this->assertSame('Cancelled', $cashTransfer->fresh()->status);
    }

    public function test_accounts_pdf_reports_render(): void
    {
        $table = $this->get('/accounts/reports/pdf?report=trial_balance&run=1');
        $table->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $table->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $table->getContent());

        $statement = $this->get('/accounts/reports/pdf?report='.urlencode('statement:balance_sheet').'&run=1');
        $statement->assertOk();
        $this->assertStringStartsWith('%PDF', $statement->getContent());
    }

    // -- Page rendering ----------------------------------------------------------------------

    public function test_all_accounts_pages_render(): void
    {
        $ledgerId = $this->ledger('1010')->id;
        $member = \App\Models\Member::first();
        $urls = [
            '/hub/accounts',
            '/accounts/transaction', '/accounts/ledger-transaction', '/accounts/journal', '/accounts/contra',
            '/accounts/vouchers', '/accounts/vouchers?preset=cancelled', '/accounts/vouchers?preset=backdate',
            '/accounts/authorization', '/accounts/authorization?mode=approval', '/accounts/cancel',
            '/accounts/recurring', '/accounts/ledgers',
            '/accounts/masters/billing-types', '/accounts/masters/setups',
            '/accounts/groups', '/accounts/group-transaction', '/accounts/group-transactions',
            '/accounts/transfer-request', '/accounts/transfer-request?kind=Product', '/accounts/transfer-requests',
            '/accounts/transfer-requests?view=receive', '/accounts/branch-balances', '/accounts/branch-statement',
            '/accounts/cash', '/accounts/cashier-balances', '/accounts/cashier-details',
            '/accounts/reports?report=account_balance&run=1',
            '/accounts/reports?report=account_receipt_payment&run=1',
            '/accounts/reports?report=account_statement&run=1',
            '/accounts/reports?report=customer_statement&run=1&member='.($member?->member_no ?? ''),
            '/accounts/reports?report=cash_book&run=1',
            '/accounts/reports?report=daily_cash_book&run=1',
            '/accounts/reports?report=ledger_statement&run=1&ledger_id='.$ledgerId,
            '/accounts/reports?report=receipt_payment&run=1',
            '/accounts/reports?report=receipt_payment_2&run=1',
            '/accounts/reports?report=income_expense_topsheet&run=1',
            '/accounts/reports?report=trial_balance&run=1',
            '/accounts/reports?report=trial_balance_2&run=1',
            '/accounts/statements?statement=balance_sheet&run=1',
            '/accounts/statements?statement=balance_sheet_2&run=1',
            '/accounts/statements?statement=income_expenses&run=1',
            '/accounts/statements?statement=income_expenses_2&run=1',
            '/accounts/statements?statement=profit_loss&run=1',
        ];
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }
}
