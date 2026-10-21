<?php

namespace App\Services;

use App\Models\BalanceImportBatch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanReceive;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Step 20A — balance migration (go-live opening balances).
 *
 * Per row: deposits open as SAVINGS / CSAV accounts and the loan is
 * hydrated with its ORIGINAL amounts + a FULL-TERM ScheduleEngine
 * schedule; the imported total_receive is applied FIFO across the rows
 * (profit before principal inside each installment, the Step 18 rule)
 * and recorded as ONE summarized loan_receives row flagged is_migrated
 * with NO GL voucher — the cash arrived before go-live and already sits
 * inside the opening balances. Current balances post as one day-one
 * Journal voucher per member through PostingService: outstanding loan
 * principal via the migration.opening mapping (Dr 1100 / Cr 3020 fund
 * balance), deposit balances as Dr 3020 / Cr savings control, with the
 * matching sub-ledger OPENING movements.
 */
class BalanceImportService
{
    /** Grid/file columns, in order. */
    public const COLUMNS = [
        'member_id', 'member_name', 'loan_product', 'opening_date',
        'no_of_inst', 'inst_freq', 'inst_amount', 'inst_from_date',
        'loan_amount', 'profit_rate', 'total_loan', 'total_receive',
        'general_savings', 'compulsory_savings',
    ];

    public function __construct(
        private ScheduleEngine $schedule,
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private NumberingService $numbering,
        private DepositAccountService $depositAccounts,
        private DepositTransactionService $depositTx,
    ) {}

    private function m2(BigDecimal|string $v): string
    {
        return (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HalfUp);
    }

    /** @return array{errors: string[], data: ?array} */
    public function validateRow(array $raw, ?Samity $samity, bool $addMembers): array
    {
        $errors = [];
        $get = fn (string $k) => isset($raw[$k]) && $raw[$k] !== '' ? trim((string) $raw[$k]) : null;
        $memberNo = $get('member_id');
        if (! $memberNo) {
            $errors[] = 'member_id is required';
        }
        $member = $memberNo
            ? Member::withoutGlobalScopes()->where('member_no', $memberNo)->first()
            : null;
        if ($memberNo && ! $member && ! $addMembers) {
            $errors[] = 'member not found and Add Members is No';
        }
        if (! $member && $addMembers && ! $get('member_name')) {
            $errors[] = 'member_name is required for a new member';
        }
        $num = function (string $k) use ($get, &$errors) {
            $v = $get($k);
            if ($v === null) {
                return '0';
            }
            if (! is_numeric(str_replace(',', '', $v))) {
                $errors[] = $k.' must be numeric';

                return '0';
            }

            return $this->m2(str_replace(',', '', $v));
        };
        $loanAmount = $num('loan_amount');
        $totalReceive = $num('total_receive');
        $general = $num('general_savings');
        $compulsory = $num('compulsory_savings');
        $product = null;
        $totalLoan = $num('total_loan');
        if (BigDecimal::of($loanAmount)->isPositive()) {
            $product = LoanProduct::where('code', (string) $get('loan_product'))->first();
            if (! $product) {
                $errors[] = 'loan_product not found';
            }
            if (! $get('no_of_inst') || (int) $get('no_of_inst') < 1) {
                $errors[] = 'no_of_inst must be at least 1';
            }
            if (! $get('inst_freq')) {
                $errors[] = 'inst_freq is required';
            }
            if (! $get('inst_from_date') || strtotime((string) $get('inst_from_date')) === false) {
                $errors[] = 'inst_from_date must be a date';
            }
            if (BigDecimal::of($totalReceive)->isGreaterThan(BigDecimal::of($totalLoan))) {
                $errors[] = 'total_receive exceeds total_loan';
            }
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'data' => null];
        }

        return ['errors' => [], 'data' => [
            'member_no' => $memberNo, 'member_name' => $get('member_name'),
            'member_id' => $member?->id, 'loan_product_id' => $product?->id,
            'opening_date' => $get('opening_date'), 'no_of_inst' => (int) ($get('no_of_inst') ?? 0),
            'inst_freq' => $get('inst_freq') ?? 'M', 'inst_amount' => $num('inst_amount'),
            'inst_from_date' => $get('inst_from_date'), 'loan_amount' => $loanAmount,
            'profit_rate' => $num('profit_rate'), 'total_loan' => $totalLoan,
            'total_receive' => $totalReceive, 'general_savings' => $general,
            'compulsory_savings' => $compulsory,
        ]];
    }

    /** Import one validated row atomically. Throws on failure. */
    public function importRow(array $data, BalanceImportBatch $batch, User $actor): void
    {
        DB::transaction(function () use ($data, $batch, $actor) {
            $samity = $batch->samity;
            $member = $data['member_id']
                ? Member::withoutGlobalScopes()->findOrFail($data['member_id'])
                : $this->createStubMember($data, $samity, $actor);
            $branchId = (int) ($samity?->branch_id ?? $member->branch_id);
            $businessDate = $this->businessDays->currentDate($branchId);
            $valueDate = $batch->transaction_date->toDateString();

            $lines = [];
            $movements = [];
            $depositAccountsTouched = [];

            // --- Loan hydration (original amounts, full-term schedule) ---
            $loan = null;
            $outstandingPrincipal = BigDecimal::zero();
            if (BigDecimal::of($data['loan_amount'])->isPositive()) {
                $product = LoanProduct::findOrFail($data['loan_product_id']);
                $rows = $this->schedule->rows(
                    $data['loan_amount'], $data['profit_rate'], $product->rate_method,
                    $data['inst_freq'], $data['no_of_inst'], $data['inst_from_date'],
                    $branchId, $samity?->id, $samity?->samity_day,
                );
                $profitTotal = collect($rows)->reduce(fn ($s, $r) => $s->plus($r['profit_due']), BigDecimal::zero());
                $grandTotal = collect($rows)->reduce(fn ($s, $r) => $s->plus($r['total_due']), BigDecimal::zero());
                $loan = LoanAccount::create([
                    'loan_number' => $this->numbering->nextDisplayId($branchId, 'loan_account'),
                    'branch_id' => $branchId, 'member_id' => $member->id, 'employee_id' => null,
                    'loan_product_id' => $product->id, 'loan_proposal_id' => null,
                    'opening_date' => $data['opening_date'] ?? $valueDate,
                    'loan_purpose' => 'Migrated loan', 'loan_amount' => $data['loan_amount'],
                    'profit_rate' => $data['profit_rate'], 'down_payment' => 0,
                    'profit_amount' => $this->m2($profitTotal), 'total_repayment' => $this->m2($grandTotal),
                    'frequency' => $data['inst_freq'], 'no_of_installments' => $data['no_of_inst'],
                    'installment_amount' => BigDecimal::of($data['inst_amount'])->isPositive() ? $data['inst_amount'] : $rows[0]['total_due'],
                    'actual_installment_amount' => $rows[0]['total_due'],
                    'last_installment_amount' => end($rows)['total_due'],
                    'installment_from_date' => $data['inst_from_date'],
                    'installment_expire_date' => end($rows)['due_date'],
                    'status' => 'Active', 'disbursed_by' => $actor->id, 'disbursed_at' => now(),
                ]);
                foreach ($rows as $row) {
                    LoanSchedule::create(['loan_account_id' => $loan->id, ...$row]);
                }
                // FIFO the imported receipts: profit before principal per row.
                $remaining = BigDecimal::of($data['total_receive']);
                $paidPrincipal = BigDecimal::zero();
                $paidProfit = BigDecimal::zero();
                if ($remaining->isPositive()) {
                    foreach ($loan->schedules()->orderBy('installment_no')->get() as $row) {
                        if ($remaining->isZero()) {
                            break;
                        }
                        $pf = BigDecimal::of((string) $row->profit_due)->isLessThan($remaining) ? BigDecimal::of((string) $row->profit_due) : $remaining;
                        $row->paid_profit = (string) $pf;
                        $remaining = $remaining->minus($pf);
                        $paidProfit = $paidProfit->plus($pf);
                        $pp = BigDecimal::of((string) $row->principal_due)->isLessThan($remaining) ? BigDecimal::of((string) $row->principal_due) : $remaining;
                        $row->paid_principal = (string) $pp;
                        $remaining = $remaining->minus($pp);
                        $paidPrincipal = $paidPrincipal->plus($pp);
                        $paidTotal = $pf->plus($pp);
                        $row->status = $paidTotal->isZero() ? 'Pending'
                            : ($paidTotal->isGreaterThanOrEqualTo(BigDecimal::of((string) $row->total_due)) ? 'Paid' : 'Partial');
                        $row->save();
                    }
                    LoanReceive::create([
                        'loan_account_id' => $loan->id, 'member_id' => $member->id,
                        'receive_date' => $valueDate, 'business_date' => $businessDate,
                        'installment_amount' => $data['total_receive'],
                        'investment_amount' => $this->m2($paidPrincipal),
                        'profit_amount' => $this->m2($paidProfit),
                        'penalty_charge' => '0', 'transfer_from_deposit' => false,
                        'comments' => 'Migrated opening receipts (batch #'.$batch->id.')',
                        'voucher_id' => null, 'is_migrated' => true,
                        'entry_by' => $actor->id, 'entry_at' => now(),
                    ]);
                }
                $outstandingPrincipal = BigDecimal::of($data['loan_amount'])->minus($paidPrincipal);
                if ($outstandingPrincipal->isPositive()) {
                    $lines[] = ['mapping_key' => 'migration.opening', 'amount' => $this->m2($outstandingPrincipal), 'loan_account_id' => $loan->id];
                    $movements[] = ['account_type' => 'loan', 'account_id' => $loan->id, 'tran_code' => 'OPENING', 'amount' => $this->m2($outstandingPrincipal), 'ref_type' => 'balance_import', 'ref_id' => $batch->id];
                }
                if (! $outstandingPrincipal->isPositive()
                    && BigDecimal::of($data['total_receive'])->isGreaterThanOrEqualTo($grandTotal)) {
                    $loan->update(['status' => 'Closed']);
                }
            }

            // --- Deposits (General Savings + Compulsory Savings) ---
            $fundLedger = Ledger::where('ledger_code', '3020')->firstOrFail();
            $savingsLedger = Ledger::where('ledger_code', '2010')->firstOrFail();
            foreach (['general_savings' => 'SAVINGS', 'compulsory_savings' => 'CSAV'] as $field => $code) {
                $amount = BigDecimal::of($data[$field]);
                if (! $amount->isPositive()) {
                    continue;
                }
                $product = DepositProduct::where('code', $code)->firstOrFail();
                $account = DepositAccount::where('member_id', $member->id)
                    ->where('deposit_product_id', $product->id)->where('is_active', true)->whereNull('closing_date')->first()
                    ?? $this->depositAccounts->open($member, $product, [
                        'phone' => $member->mobile, 'opening_date' => $valueDate,
                    ], $actor->id);
                $lines[] = ['ledger_id' => $fundLedger->id, 'debit' => $this->m2($amount), 'credit' => '0'];
                $lines[] = ['ledger_id' => $savingsLedger->id, 'debit' => '0', 'credit' => $this->m2($amount)];
                $movements[] = ['account_type' => 'deposit', 'account_id' => $account->id, 'tran_code' => 'OPENING', 'amount' => $this->m2($amount), 'ref_type' => 'balance_import', 'ref_id' => $batch->id];
                $depositAccountsTouched[] = $account;
            }

            // --- The day-one opening voucher ---
            if ($lines !== []) {
                $this->posting->post([
                    'branch_id' => $branchId, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                    'transaction_date' => $businessDate,
                    'comments' => 'Balance migration opening (batch #'.$batch->id.', member '.$member->member_no.')',
                    'lines' => $lines, 'movements' => $movements,
                ]);
                foreach ($depositAccountsTouched as $account) {
                    $this->depositTx->resyncCache($account);
                }
            }
        });
    }

    /**
     * Minimal member for Add Members = Yes rows: the file carries only the
     * legacy ID + name, so the mobile is a system placeholder derived from
     * the legacy ID (flagged for the officer to correct on the profile —
     * see BUILD_LOG Step 20A). No portal user is created for stubs.
     */
    private function createStubMember(array $data, ?Samity $samity, User $actor): Member
    {
        $digits = preg_replace('/\D/', '', (string) $data['member_no']);
        $mobile = '01'.str_pad(substr($digits, -9), 9, '0', STR_PAD_LEFT);
        if (Member::withoutGlobalScopes()->where('mobile', $mobile)->exists()) {
            throw new \RuntimeException('placeholder mobile collision for member '.$data['member_no']);
        }

        return Member::create([
            'member_no' => $data['member_no'], 'branch_id' => $samity?->branch_id,
            'samity_id' => $samity?->id, 'name' => $data['member_name'],
            'mobile' => $mobile, 'open_date' => now()->toDateString(),
            'status' => 'Active', 'created_by' => $actor->id,
        ]);
    }
}
