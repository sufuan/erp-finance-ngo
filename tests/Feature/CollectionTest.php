<?php

namespace Tests\Feature;

use App\Models\CollectionBatch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanReceive;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CollectionReportService;
use App\Services\CollectionService;
use App\Services\DepositAccountService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Steps 21–23 — Collection module: sheet/member posting fans out to the
 * deposit + loan engines atomically, cancels delegate to the engines'
 * latest-only rules, other collections + instruments post through
 * PostingService, and the report registry runs branch-scoped.
 */
class CollectionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private CollectionService $service;
    private Samity $samity;
    /** @var Member[] */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->service = app(CollectionService::class);
        $this->samity = Samity::whereHas('members')->firstOrFail();
        $this->members = Member::where('samity_id', $this->samity->id)
            ->where('status', 'Active')->orderBy('id')->limit(3)->get()->all();
        $this->assertGreaterThanOrEqual(2, count($this->members), 'Seeded samity needs 2+ active members');
    }

    private string $cashBeforeSheet = '0.00';

    private function cashOf(User $user): string
    {
        $value = \App\Models\CashLedger::where('user_id', $user->id)->value('current_balance');

        return $value === null ? '0.00' : (string) \Brick\Math\BigDecimal::of((string) $value)->toScale(2);
    }

    private function openDeposit(Member $member, string $productCode = 'SAVINGS', ?string $installment = null): DepositAccount
    {
        $product = DepositProduct::where('code', $productCode)->firstOrFail();

        return app(DepositAccountService::class)->open($member, $product, [
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
            'installment_amount' => $installment,
        ], $this->admin->id);
    }

    private function disbursedLoan(Member $member, array $overrides = []): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open(array_merge([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $overrides), $this->admin);

        return app(LoanMoneyService::class)->disburse($loan, $this->admin);
    }

    public function test_sheet_posting_fans_out_to_deposit_loan_and_share(): void
    {
        [$m1, $m2] = $this->members;
        $savings = $this->openDeposit($m1, 'SAVINGS');
        $dps = $this->openDeposit($m2, 'DPS', '500');
        $loan = $this->disbursedLoan($m1);
        // The engine resyncs members.share_amount from the share
        // sub-ledger, so measure the ledger (the seed's cached column
        // has no ledger backing).
        $shareBefore = (string) \App\Models\AccountTransaction::where('account_type', 'share')
            ->where('account_id', $m1->id)->whereNull('cancel_at')->sum('amount');
        $outstandingBefore = app(LoanMoneyService::class)->outstanding($loan)['total'];

        $this->cashBeforeSheet = $this->cashOf($this->admin);
        $grid = $this->service->gridForSamity($this->samity);
        $row1 = collect($grid)->firstWhere('member_id', $m1->id);
        $loanCell = collect($row1['accounts'])->firstWhere('account_type', 'loan');
        $this->assertSame('1083.10', $loanCell['receivable'], 'Loan receivable = installment (nothing due yet)');

        $batch = $this->service->postBatch($this->admin, [
            'kind' => 'sheet', 'samity_id' => $this->samity->id,
            'lines' => [
                ['member_id' => $m1->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '200'],
                ['member_id' => $m1->id, 'account_type' => 'loan', 'account_id' => $loan->id, 'amount' => '1083.10'],
                ['member_id' => $m1->id, 'account_type' => 'share', 'account_id' => $m1->id, 'amount' => '100'],
                ['member_id' => $m2->id, 'account_type' => 'deposit', 'account_id' => $dps->id, 'amount' => '500'],
            ],
        ]);

        $this->assertSame('1883.10', (string) $batch->total_receive);
        $this->assertSame('1083.10', (string) $batch->loan_receive);
        $this->assertSame('700.00', (string) $batch->deposit_receive);
        $this->assertSame('100.00', (string) $batch->share_receive);
        $this->assertSame(4, $batch->lines()->count());

        $this->assertSame('200.00', (string) $savings->fresh()->current_balance);
        $this->assertSame('500.00', (string) $dps->fresh()->current_balance);
        $this->assertDatabaseHas('loan_receives', ['loan_account_id' => $loan->id, 'installment_amount' => '1083.10']);
        $outstandingAfter = app(LoanMoneyService::class)->outstanding($loan->fresh())['total'];
        $this->assertSame(
            (string) (\Brick\Math\BigDecimal::of($outstandingBefore)->minus('1083.10')->toScale(2)),
            $outstandingAfter
        );
        $this->assertSame(
            (string) (\Brick\Math\BigDecimal::of($shareBefore)->plus('100')->toScale(2)),
            (string) $m1->fresh()->share_amount
        );
        // Officer's cash-in-hand rose by the full sheet total.
        $cash = $this->cashOf($this->admin);
        $this->assertSame('1883.10', (string) (\Brick\Math\BigDecimal::of($cash)->minus($this->cashBeforeSheet)->toScale(2)));
        $tb = app(PostingService::class)->trialBalance($this->samity->branch_id);
        $this->assertTrue($tb['balanced']);
    }

    public function test_sheet_with_one_bad_row_posts_nothing(): void
    {
        [$m1, $m2] = $this->members;
        $savings = $this->openDeposit($m1);
        $loan = $this->disbursedLoan($m1);
        // A second, NOT disbursed loan for member 2 — the engine refuses receives on it.
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $undisbursed = app(LoanOpeningService::class)->open([
            'member_id' => $m2->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Trade', 'loan_amount' => '5000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 10, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $this->admin);

        $batchesBefore = CollectionBatch::count();
        $txnsBefore = DepositTransaction::count();
        $receivesBefore = LoanReceive::count();
        try {
            $this->service->postBatch($this->admin, [
                'kind' => 'sheet', 'samity_id' => $this->samity->id,
                'lines' => [
                    ['member_id' => $m1->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '300'],
                    ['member_id' => $m2->id, 'account_type' => 'loan', 'account_id' => $undisbursed->id, 'amount' => '500'],
                ],
            ]);
            $this->fail('Sheet with an un-disbursed loan row must be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors()['lines'] ?? [], 'Row errors are reported per line');
        }
        $this->assertSame($batchesBefore, CollectionBatch::count());
        $this->assertSame($txnsBefore, DepositTransaction::count());
        $this->assertSame($receivesBefore, LoanReceive::count());
        $this->assertSame('0.00', (string) $savings->fresh()->current_balance);
    }

    public function test_member_collection_and_batch_cancel_reverses(): void
    {
        $m1 = $this->members[0];
        $savings = $this->openDeposit($m1);
        $loan = $this->disbursedLoan($m1);

        $batch = $this->service->postBatch($this->admin, [
            'kind' => 'member', 'member_id' => $m1->id,
            'lines' => [
                ['member_id' => $m1->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '150'],
                ['member_id' => $m1->id, 'account_type' => 'loan', 'account_id' => $loan->id, 'amount' => '1083.10'],
            ],
        ]);
        $this->assertSame('150.00', (string) $savings->fresh()->current_balance);

        $this->service->cancelBatch($batch, $this->admin, 'Wrong sheet');
        $batch->refresh();
        $this->assertSame('Cancelled', $batch->status);
        $this->assertSame('0.00', (string) $batch->total_receive);
        $this->assertSame('0.00', (string) $savings->fresh()->current_balance);
        $this->assertSame('0.00', (string) \Brick\Math\BigDecimal::of((string) LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->sum('installment_amount'))->toScale(2));
        $tb = app(PostingService::class)->trialBalance($this->samity->branch_id);
        $this->assertTrue($tb['balanced']);
    }

    public function test_cancel_line_blocked_when_a_later_movement_exists(): void
    {
        $m1 = $this->members[0];
        $savings = $this->openDeposit($m1);
        $batch = $this->service->postBatch($this->admin, [
            'kind' => 'member', 'member_id' => $m1->id,
            'lines' => [
                ['member_id' => $m1->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '100'],
            ],
        ]);
        $line = $batch->lines()->firstOrFail();
        // A later direct receive on the same account makes the sheet line non-latest.
        app(\App\Services\DepositTransactionService::class)->receive($savings->fresh(), '50', null, 'Direct', $this->admin);
        try {
            $this->service->cancelLine($line, $this->admin, 'Too late');
            $this->fail('Non-latest line cancel must be refused.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('Posted', $line->fresh()->status);
        $this->assertSame('150.00', (string) $savings->fresh()->current_balance);
    }

    public function test_other_collection_posts_balanced_voucher_and_cancels(): void
    {
        $other = $this->service->postOther($this->admin, [
            'branch_id' => $this->samity->branch_id, 'member_id' => $this->members[0]->id,
            'collection_type' => 'Admission Fee', 'collection_account' => 'Cash',
            'amount' => '250', 'document_number' => 'DOC-9', 'comments' => 'Admission',
        ]);
        $voucher = Voucher::findOrFail($other->voucher_id);
        $this->assertSame((string) $voucher->lines()->sum('debit'), (string) $voucher->lines()->sum('credit'));
        $this->assertSame('250.00', $this->cashOf($this->admin));

        $this->service->cancelOther($other, $this->admin, 'Duplicate');
        $this->assertNotNull($other->fresh()->cancel_at);
        $this->assertSame('0.00', $this->cashOf($this->admin));
    }

    public function test_passbook_and_cheque_issue_with_fee_and_cancel(): void
    {
        $m1 = $this->members[0];
        $passbook = $this->service->issuePassbook($this->admin, [
            'member_id' => $m1->id, 'book_number' => 'PB-100', 'fee_amount' => '30', 'document_number' => null,
        ]);
        $this->assertNotNull($passbook->voucher_id);
        $cheque = $this->service->issueChequeBook($this->admin, [
            'member_id' => $m1->id, 'cheque_prefix' => 'CHQ', 'start_leaf' => '001', 'end_leaf' => '010',
            'fee_amount' => '50', 'document_number' => null,
        ]);
        $this->assertSame('80.00', $this->cashOf($this->admin));

        $this->service->cancelPassbook($passbook, $this->admin, 'Wrong number');
        $this->assertNotNull($passbook->fresh()->cancel_at);
        $this->assertSame('50.00', $this->cashOf($this->admin));
        $tb = app(PostingService::class)->trialBalance($this->samity->branch_id);
        $this->assertTrue($tb['balanced']);
    }

    public function test_reports_run_and_pomis1_reconciles_with_members(): void
    {
        $m1 = $this->members[0];
        $savings = $this->openDeposit($m1);
        $this->service->postBatch($this->admin, [
            'kind' => 'member', 'member_id' => $m1->id,
            'lines' => [
                ['member_id' => $m1->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '400'],
            ],
        ]);

        $reports = app(CollectionReportService::class);
        $filters = [
            '_branch_ids' => \App\Models\Branch::pluck('id')->all(),
            '_business_date' => now()->toDateString(),
            'from_date' => '2020-01-01', 'to_date' => '2030-01-01',
            'report_date' => now()->toDateString(), 'year' => (string) now()->year,
        ];
        foreach (array_keys($reports->registry()) as $key) {
            $result = $reports->run($key, $filters);
            $this->assertArrayHasKey('columns', $result, $key);
            $this->assertNotEmpty($result['columns'], $key.' must produce columns');
        }

        $pomis1 = $reports->run('pomis_1', $filters);
        $totalMembersCol = array_search('total_members', $pomis1['columns']);
        // POMIS-1 grand total members = active members across in-scope samities.
        $sum = 0;
        foreach ($pomis1['rows'] as $row) {
            $sum += (int) $row[$totalMembersCol];
        }
        $expected = Member::where('status', 'Active')->count();
        $this->assertSame($expected, $sum);

        $summary = $reports->run('collection_summary', $filters);
        $totalCol = array_search('total_receive', $summary['columns']);
        $this->assertSame('400.00', $summary['total_row'][$totalCol]);
    }

    public function test_grid_marks_share_row_and_zero_lines_are_skipped(): void
    {
        $m1 = $this->members[0];
        $grid = $this->service->gridForMember($m1);
        $this->assertCount(1, $grid);
        $share = collect($grid[0]['accounts'])->firstWhere('account_type', 'share');
        $this->assertNotNull($share);
        try {
            $this->service->postBatch($this->admin, [
                'kind' => 'member', 'member_id' => $m1->id,
                'lines' => [
                    ['member_id' => $m1->id, 'account_type' => 'share', 'account_id' => $m1->id, 'amount' => '0'],
                ],
            ]);
            $this->fail('All-zero sheet must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
