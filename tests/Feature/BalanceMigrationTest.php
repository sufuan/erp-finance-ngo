<?php

namespace Tests\Feature;

use App\Jobs\ImportBalancesJob;
use App\Models\AccountTransaction;
use App\Models\BalanceImportBatch;
use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanReceive;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\BalanceImportService;
use App\Services\LoanMoneyService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Step 20A — balance migration: hydration, one migrated receipt, opening vouchers. */
class BalanceMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Member $member;
    private Samity $samity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->member = Member::query()->firstOrFail();
        $this->samity = Samity::query()->firstOrFail();

        // A flat 15% product so 10,000 × 1yr = exactly 1,500 profit.
        $src = LoanProduct::query()->firstOrFail();
        $attrs = collect($src->getAttributes())->except(['id', 'created_at', 'updated_at'])
            ->merge(['code' => '9001', 'name_en' => 'Migration Flat', 'name_bn' => 'মাইগ্রেশন ফ্ল্যাট', 'rate_method' => 'flat', 'profit_rate' => 15])
            ->all();
        LoanProduct::create($attrs);
    }

    private function batch(array $rows, bool $addMembers = false): BalanceImportBatch
    {
        return BalanceImportBatch::create([
            'created_by' => $this->admin->id, 'samity_id' => $this->samity->id,
            'branch_id' => $this->samity->branch_id, 'transaction_date' => '2026-10-21',
            'add_members' => $addMembers, 'source' => 'grid', 'status' => 'Preview',
            'total_rows' => count($rows),
            'rows' => collect($rows)->map(fn ($raw, $i) => ['no' => $i + 1, 'raw' => $raw, 'errors' => []])->all(),
        ]);
    }

    private function goodRow(array $overrides = []): array
    {
        return array_merge([
            'member_id' => $this->member->member_no, 'member_name' => $this->member->name,
            'loan_product' => '9001', 'opening_date' => '2026-04-01', 'no_of_inst' => '12',
            'inst_freq' => 'M', 'inst_amount' => '958.33', 'inst_from_date' => '2026-05-01',
            'loan_amount' => '10000', 'profit_rate' => '15', 'total_loan' => '11500',
            'total_receive' => '4000', 'general_savings' => '3000', 'compulsory_savings' => '1000',
        ], $overrides);
    }

    public function test_mid_cycle_loan_hydrates_with_full_term_schedule_and_one_migrated_receipt(): void
    {
        $badRow = $this->goodRow(['member_id' => Member::query()->skip(1)->firstOrFail()->member_no, 'total_receive' => '99999']);
        $batch = $this->batch([$this->goodRow(), $badRow]);
        (new ImportBalancesJob($batch->id))->handle(app(BalanceImportService::class));

        $batch->refresh();
        $this->assertSame('Completed', $batch->status);
        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(1, $batch->failed_count); // bad row fails, good row still imports

        $loan = LoanAccount::where('member_id', $this->member->id)->latest('id')->firstOrFail();
        $this->assertSame('10000.00', (string) $loan->loan_amount);
        $this->assertSame('1500.00', (string) $loan->profit_amount);
        $this->assertSame('Active', $loan->status);
        $this->assertCount(12, $loan->schedules);
        foreach ([1, 2, 3, 4] as $no) {
            $this->assertSame('Paid', $loan->schedules()->where('installment_no', $no)->first()->status);
        }
        $row5 = $loan->schedules()->where('installment_no', 5)->first();
        $this->assertSame('Partial', $row5->status);
        $this->assertSame('125.00', (string) $row5->paid_profit);
        $this->assertSame('41.68', (string) $row5->paid_principal);

        $out = app(LoanMoneyService::class)->outstanding($loan);
        $this->assertSame('6625.00', $out['principal']);
        $this->assertSame('875.00', $out['profit']);
        $this->assertSame('7500.00', $out['total']); // remaining due per the plan example

        $receipts = LoanReceive::where('loan_account_id', $loan->id)->get();
        $this->assertCount(1, $receipts);
        $this->assertTrue($receipts[0]->is_migrated);
        $this->assertNull($receipts[0]->voucher_id); // no GL voucher for pre-go-live cash
        $this->assertSame('3375.00', (string) $receipts[0]->investment_amount);
        $this->assertSame('625.00', (string) $receipts[0]->profit_amount);

        // Opening voucher balances: loan sub-ledger = outstanding principal,
        // deposit caches carry the migrated balances, TB still balances.
        $loanBalance = AccountTransaction::where('account_type', 'loan')->where('account_id', $loan->id)
            ->whereNull('cancel_at')->orderByDesc('id')->value('balance_after');
        $this->assertSame('6625.00', (string) $loanBalance);
        $savings = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $csav = DepositProduct::where('code', 'CSAV')->firstOrFail();
        $this->assertSame('3000.00', (string) \App\Models\DepositAccount::where('member_id', $this->member->id)->where('deposit_product_id', $savings->id)->first()->current_balance);
        $this->assertSame('1000.00', (string) \App\Models\DepositAccount::where('member_id', $this->member->id)->where('deposit_product_id', $csav->id)->first()->current_balance);
        $this->assertTrue(app(PostingService::class)->trialBalance($loan->branch_id)['balanced']);
    }

    public function test_add_members_creates_stub_and_posts_deposits(): void
    {
        $batch = $this->batch([$this->goodRow([
            'member_id' => 'LEGACY-777', 'member_name' => 'Legacy Member',
            'loan_amount' => '0', 'total_loan' => '0', 'total_receive' => '0',
            'general_savings' => '500', 'compulsory_savings' => '0',
        ])], addMembers: true);
        (new ImportBalancesJob($batch->id))->handle(app(BalanceImportService::class));

        $batch->refresh();
        $this->assertSame(1, $batch->imported_count);
        $stub = Member::withoutGlobalScopes()->where('member_no', 'LEGACY-777')->firstOrFail();
        $this->assertSame('Legacy Member', $stub->name);
        $savings = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $this->assertSame('500.00', (string) \App\Models\DepositAccount::where('member_id', $stub->id)->where('deposit_product_id', $savings->id)->first()->current_balance);
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->admin)->get('/settings/balance-migrations')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Settings/BalanceMigrations'));
    }
}
