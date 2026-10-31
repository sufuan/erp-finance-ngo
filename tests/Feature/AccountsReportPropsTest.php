<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ledger;
use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AccountReportService;
use App\Services\BusinessDayService;
use Tests\Feature\Crud\CrudTestCase;

/**
 * Regression guard for the AccountReportController::build() array-union
 * defect (shipped in sync #65, found during the Accounts video filming):
 * build() returned `$empty + [...]`, and PHP's union keeps the LEFT
 * operand — so every tabular Accounts report page rendered EMPTY rows /
 * totals in the UI even though AccountReportService computed correctly
 * and the service-layer reconciliation passed. These tests assert at the
 * layer that was missed: the actual Inertia props of each report page,
 * with GL data present, must carry the service's rows and totals.
 */
class AccountsReportPropsTest extends CrudTestCase
{
    private Branch $b102;
    private User $officer;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->b102 = $this->branch('102');
        $this->post('/branch/switch', ['branch_id' => $this->b102->id])->assertRedirect();
    }

    private function ledger(string $code): Ledger
    {
        return Ledger::where('ledger_code', $code)->firstOrFail();
    }

    private function biz(): string
    {
        return app(BusinessDayService::class)->currentDate($this->b102->id);
    }

    private function fyFrom(): string
    {
        return app(AccountReportService::class)->fiscalYearStart($this->b102->id, $this->biz());
    }

    private function officer(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('accountant');
        $user->branches()->attach($this->b102->id);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->post('/branch/switch', ['branch_id' => $this->b102->id])->assertRedirect();
    }

    /** Read the Inertia page props from the rendered HTML shell. */
    private function inertiaProps(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $found = preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $this->assertSame(1, $found, 'Inertia shell carries the page JSON');

        return json_decode($m[1], true)['props'] ?? [];
    }

    private function money(mixed $x): string
    {
        return bccomp((string) $x, '0', 2) === 0 ? '0.00' : bcadd((string) $x, '0', 2);
    }

    private function assertMoneySame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame($this->money($expected), $this->money($actual), $message);
    }

    /**
     * The curated GL scenario from the build-time reconciliation, posted
     * through the real forms on branch 102, plus a share receive so the
     * account/customer statements have rows:
     *   Receipt 3,000 → income 4030 (cash, auto-authorized)
     *   Payment 1,200 → expense 5010 (bank, authorized below)
     *   Journal 800   → expense 5040 Dr / liability 2060 Cr (authorized)
     *   Contra 500    → cash → bank (authorized)
     *   Share receive 250 for a branch-102 member
     */
    private function scenario(): void
    {
        $this->officer = $this->officer();
        $this->actAs($this->officer);
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

        $this->actAs($this->admin);
        foreach ([$payment, $journal, $contra] as $v) {
            $this->post("/accounts/vouchers/{$v->id}/authorize")->assertSessionHas('success');
        }

        $this->member = Member::where('branch_id', $this->b102->id)->firstOrFail();
        $this->post('/deposits/share', [
            'member_id' => $this->member->id, 'mode' => 'receive', 'amount' => '250',
            'document_number' => 'SH-REP-1', 'comments' => null,
        ])->assertRedirect()->assertSessionHas('success');
    }

    private function reportUrl(string $key, array $extra = []): string
    {
        return '/accounts/reports?'.http_build_query(array_merge([
            'report' => $key, 'run' => 1, 'from' => $this->fyFrom(), 'to' => $this->biz(),
        ], $extra));
    }

    public function test_trial_balance_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->trialBalance([$this->b102->id], null, $this->biz());

        $props = $this->inertiaProps($this->reportUrl('trial_balance'));

        $this->assertTrue($props['ran']);
        $this->assertNotEmpty($props['columns']);
        $this->assertNotEmpty($props['rows'], 'trial balance rows must reach the page');
        $this->assertNotNull($props['totalRow']);
        $this->assertMoneySame($svc['total_debit'], $props['totalRow'][3]);
        $this->assertMoneySame($svc['total_credit'], $props['totalRow'][4]);
        $this->assertTrue($props['summary']['balanced']);
        $income = collect($props['rows'])->firstWhere(0, '4030');
        $this->assertNotNull($income, 'income ledger 4030 row present');
        $svcRow = collect($svc['rows'])->firstWhere('code', '4030');
        $this->assertMoneySame($svcRow['tb_credit'], $income[4]);
        $this->assertGreaterThanOrEqual(3000.0, (float) $income[4]);
    }

    public function test_trial_balance_2_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->trialBalance([$this->b102->id], $this->fyFrom(), $this->biz());

        $props = $this->inertiaProps($this->reportUrl('trial_balance_2'));

        $this->assertNotEmpty($props['rows']);
        $this->assertNotNull($props['totalRow']);
        $this->assertMoneySame($svc['totals']['closing_debit'], $props['totalRow'][6]);
        $this->assertMoneySame($svc['totals']['closing_credit'], $props['totalRow'][7]);
        $this->assertTrue($props['summary']['balanced']);
        $income = collect($props['rows'])->firstWhere(0, '4030');
        $this->assertNotNull($income);
        $svcRow = collect($svc['rows'])->firstWhere('code', '4030');
        $this->assertMoneySame($svcRow['credit'], $income[5]);
    }

    public function test_account_balance_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->ledgerBalances([$this->b102->id], null, $this->biz());

        $props = $this->inertiaProps($this->reportUrl('account_balance'));

        $this->assertNotEmpty($props['rows'], 'account balance rows must reach the page');
        $cash = collect($props['rows'])->firstWhere(0, '1010');
        $this->assertNotNull($cash);
        $this->assertMoneySame(collect($svc)->firstWhere('code', '1010')['balance'], $cash[3]);
        $income = collect($props['rows'])->firstWhere(0, '4030');
        $this->assertNotNull($income);
        $this->assertMoneySame(collect($svc)->firstWhere('code', '4030')['balance'], $income[3]);
    }

    public function test_account_receipt_payment_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->accountReceiptPayment([$this->b102->id], $this->fyFrom(), $this->biz());

        $props = $this->inertiaProps($this->reportUrl('account_receipt_payment'));

        $this->assertNotEmpty($props['rows']);
        $income = collect($props['rows'])->firstWhere(0, '4030');
        $this->assertNotNull($income);
        $svcRow = collect($svc)->firstWhere('code', '4030');
        $this->assertMoneySame($svcRow['credit'], $income[4]);
        $this->assertGreaterThanOrEqual(3000.0, (float) $income[4]);
        $expense = collect($props['rows'])->firstWhere(0, '5010');
        $this->assertNotNull($expense);
        $this->assertMoneySame(collect($svc)->firstWhere('code', '5010')['debit'], $expense[3]);
    }

    public function test_ledger_statement_page_props_carry_service_data(): void
    {
        $this->scenario();
        $ledger = $this->ledger('4030');
        $svc = app(AccountReportService::class)->ledgerStatement([$this->b102->id], $ledger, $this->fyFrom(), $this->biz());

        $props = $this->inertiaProps($this->reportUrl('ledger_statement', ['ledger_id' => $ledger->id]));

        $this->assertNotEmpty($props['rows'], 'ledger statement rows must reach the page');
        $this->assertCount(count($svc['rows']), $props['rows']);
        $this->assertNotNull($props['totalRow']);
        $this->assertMoneySame($svc['total_debit'], $props['totalRow'][3]);
        $this->assertMoneySame($svc['total_credit'], $props['totalRow'][4]);
        $this->assertMoneySame($svc['closing'], $props['totalRow'][5]);
        $this->assertMoneySame($svc['closing'], $props['summary']['closing']);
        $amounts = collect($props['rows'])->map(fn ($r) => (float) $r[4]);
        $this->assertTrue($amounts->contains(3000.0), 'the 3,000 receipt line is on the page');
    }

    public function test_cash_book_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->cashBook([$this->b102->id], $this->fyFrom(), $this->biz());

        $props = $this->inertiaProps($this->reportUrl('cash_book'));

        $this->assertNotEmpty($props['rows'], 'cash book rows must reach the page');
        $this->assertCount(count($svc['rows']), $props['rows']);
        $this->assertMoneySame($svc['total_debit'], $props['totalRow'][3]);
        $this->assertMoneySame($svc['closing'], $props['summary']['closing']);
        $debits = collect($props['rows'])->map(fn ($r) => (float) $r[3]);
        $this->assertTrue($debits->contains(3000.0), 'the 3,000 cash receipt is on the page');
    }

    public function test_daily_cash_book_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->dailyCashBook($this->b102->id, $this->biz());

        $props = $this->inertiaProps($this->reportUrl('daily_cash_book', ['date' => $this->biz()]));

        $this->assertNotEmpty($props['rows'], 'daily cash book cashier rows must reach the page');
        $this->assertCount(count($svc['cashiers']), $props['rows']);
        $cashIns = collect($props['rows'])->map(fn ($r) => (float) $r[1]);
        $this->assertGreaterThanOrEqual(3000.0, $cashIns->max(), "the posting officer's 3,000 cash-in is on the page");
        $this->assertMoneySame($svc['closing'], $props['summary']['closing']);
        $this->assertMoneySame($svc['receipts'], $props['summary']['receipts']);
    }

    public function test_account_statement_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->accountStatement('share', $this->member->id, $this->fyFrom(), $this->biz());

        $props = $this->inertiaProps($this->reportUrl('account_statement', [
            'account_type' => 'share', 'account_id' => $this->member->id,
        ]));

        $this->assertNotEmpty($props['rows'], 'account statement rows must reach the page');
        $amounts = collect($props['rows'])->map(fn ($r) => (float) $r[2]);
        $this->assertTrue($amounts->contains(250.0), 'the 250 share receive is on the page');
        $this->assertMoneySame($svc['closing'], $props['summary']['closing']);
    }

    public function test_customer_statement_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->customerStatement($this->member->id, $this->fyFrom(), $this->biz());

        $props = $this->inertiaProps($this->reportUrl('customer_statement', ['member' => $this->member->member_no]));

        $this->assertNotEmpty($props['rows'], 'customer statement rows must reach the page');
        $shareRows = collect($props['rows'])->where(1, 'Share Capital');
        $this->assertNotEmpty($shareRows->all(), 'share capital rows present');
        $this->assertStringContainsString($this->member->member_no, (string) $props['summary']['member']);
        $this->assertMoneySame($svc['share_amount'], $props['summary']['share_amount']);
    }

    public function test_receipt_payment_pages_props_carry_service_data(): void
    {
        $this->scenario();
        $reports = app(AccountReportService::class);
        $svc = $reports->receiptPayment([$this->b102->id], $this->fyFrom(), $this->biz(), false);

        $props = $this->inertiaProps($this->reportUrl('receipt_payment'));
        $this->assertCount(2, $props['sections']);
        $receipts = collect($props['sections'])->firstWhere('heading', 'receipts');
        $this->assertNotEmpty($receipts['rows'], 'receipt section rows must reach the page');
        $this->assertMoneySame($svc['total_receipts'], $props['summary']['total_receipts']);
        $this->assertGreaterThanOrEqual(3000.0, (float) $props['summary']['total_receipts']);
        $this->assertMoneySame($svc['closing'], $props['summary']['closing']);

        $svc2 = $reports->receiptPayment([$this->b102->id], $this->fyFrom(), $this->biz(), true);
        $props2 = $this->inertiaProps($this->reportUrl('receipt_payment_2'));
        $receipts2 = collect($props2['sections'])->firstWhere('heading', 'receipts');
        $this->assertNotEmpty($receipts2['groups'], 'grouped receipt rows must reach the page');
        $this->assertMoneySame($svc2['total_receipts'], $props2['summary']['total_receipts']);
        $this->assertMoneySame($svc2['total_payments'], $props2['summary']['total_payments']);
    }

    public function test_income_expense_topsheet_page_props_carry_service_data(): void
    {
        $this->scenario();
        $svc = app(AccountReportService::class)->incomeExpense([$this->b102->id], $this->fyFrom(), $this->biz());

        $props = $this->inertiaProps($this->reportUrl('income_expense_topsheet'));

        $this->assertCount(2, $props['sections']);
        $income = collect($props['sections'])->firstWhere('heading', 'top_income_heads');
        $this->assertNotEmpty($income['rows'], 'top income rows must reach the page');
        $labels = collect($income['rows'])->map(fn ($r) => $r[0]);
        $this->assertTrue($labels->contains(fn ($l) => str_starts_with((string) $l, '4030')), 'income 4030 is a top head');
        $this->assertMoneySame($svc['surplus'], $props['summary']['surplus']);
        $this->assertMoneySame($svc['total_income'], $income['total'][1]);
    }

    public function test_statements_pages_props_carry_data(): void
    {
        $this->scenario();
        $reports = app(AccountReportService::class);
        $bs = $reports->balanceSheet([$this->b102->id], $this->biz());

        $props = $this->inertiaProps('/accounts/statements?statement=balance_sheet&run=1&from='.$this->fyFrom().'&to='.$this->biz());
        $this->assertTrue($props['ran']);
        $this->assertNotEmpty($props['sections']);
        $assets = collect($props['sections'])->firstWhere('heading', 'assets');
        $this->assertNotEmpty($assets['rows']);
        $this->assertTrue($props['summary']['balanced']);
        $this->assertMoneySame($bs['total_assets'], $props['summary']['total_assets']);

        $propsIe = $this->inertiaProps('/accounts/statements?statement=income_expenses&run=1&from='.$this->fyFrom().'&to='.$this->biz());
        $income = collect($propsIe['sections'])->firstWhere('heading', 'income');
        $this->assertNotEmpty($income['rows']);
        $ie = $reports->incomeExpense([$this->b102->id], $this->fyFrom(), $this->biz());
        $this->assertMoneySame($ie['total_income'], $income['total'][1]);
    }
}
