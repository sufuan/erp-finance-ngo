<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\LoanLossProvision;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Step 20 — Loan Loss Provisioning (MRA). Classification by days overdue
 * of the earliest unpaid installment; provision % from settings.llp.rates
 * {standard 1, watchlist 5, substandard 25, doubtful 75, loss 100}.
 * Basis (binding, settings.llp.basis = net_outstanding): provision is
 * calculated on outstanding principal MINUS the member's eligible
 * compulsory-savings (CSAV) balance — never on gross outstanding.
 * Posting a run creates ONE provision journal per branch moving that
 * branch's 1120 Loan Loss Provision balance to the required total
 * (delta up via the loan.provision mapping; delta down as an explicit
 * reversal), so the provision balance rolls forward run to run.
 */
class LlpService
{
    public function __construct(
        private SettingsService $settings,
        private LoanMoneyService $money,
        private PostingService $posting,
        private BusinessDayService $businessDays,
    ) {}

    private function m2(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }

    /** @return array{bucket: string, overdue_days: int} */
    public function classify(LoanAccount $loan, string $reportDate): array
    {
        $earliest = $loan->schedules()->whereNull('superseded_by')
            ->where('status', '!=', 'Paid')
            ->whereRaw('(principal_due - paid_principal) + (profit_due - paid_profit) > 0')
            ->min('due_date');
        if (! $earliest) {
            return ['bucket' => 'standard', 'overdue_days' => 0];
        }
        $days = (int) Carbon::parse($earliest)->diffInDays(Carbon::parse($reportDate), false);
        if ($days <= 0) {
            return ['bucket' => 'standard', 'overdue_days' => 0];
        }

        return [
            'bucket' => match (true) {
                $days <= 30 => 'watchlist',
                $days <= 180 => 'substandard',
                $days <= 365 => 'doubtful',
                default => 'loss',
            },
            'overdue_days' => $days,
        ];
    }

    /** The member's eligible compulsory savings balance (CSAV accounts). */
    public function compulsorySavings(LoanAccount $loan): string
    {
        if (! $loan->member_id) {
            return '0.00';
        }

        return (string) DepositAccount::query()
            ->where('member_id', $loan->member_id)
            ->whereHas('product', fn ($q) => $q->where('code', 'CSAV'))
            ->sum('current_balance');
    }

    /**
     * @param  array{account_type?: ?int, samity_id?: ?int, employee_id?: ?int, member?: ?string, frequency?: ?string, report_date: string}  $filters
     * @return array{rows: array<int, array<string, mixed>>, totals: array{outstanding: string, required: string}}
     */
    public function compute(array $filters): array
    {
        $rates = $this->settings->get('llp.rates', ['standard' => 1, 'watchlist' => 5, 'substandard' => 25, 'doubtful' => 75, 'loss' => 100]);
        $reportDate = $filters['report_date'];
        $loans = LoanAccount::query()
            ->whereIn('status', ['Disbursed', 'Active'])
            ->when(! empty($filters['account_type']), fn ($q) => $q->where('loan_product_id', $filters['account_type']))
            ->when(! empty($filters['frequency']), fn ($q) => $q->where('frequency', $filters['frequency']))
            ->when(! empty($filters['member']), fn ($q) => $q->whereHas('member', fn ($m) => $m->where('member_no', 'like', '%'.$filters['member'].'%')))
            ->when(! empty($filters['samity_id']), fn ($q) => $q->whereHas('member', fn ($m) => $m->where('samity_id', $filters['samity_id'])))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->whereHas('member.samity', fn ($s) => $s->where('field_officer_id', $filters['employee_id'])))
            ->with(['member.samity', 'product'])
            ->orderBy('id')->get();

        $rows = [];
        $totalBasis = BigDecimal::zero();
        $totalRequired = BigDecimal::zero();
        foreach ($loans as $loan) {
            $outstanding = $this->money->outstanding($loan);
            $gross = BigDecimal::of($outstanding['principal']);
            if (! $gross->isPositive()) {
                continue;
            }
            $savings = BigDecimal::of($this->compulsorySavings($loan));
            $basis = $gross->minus($savings);
            if ($basis->isNegative()) {
                $basis = BigDecimal::zero();
            }
            $class = $this->classify($loan, $reportDate);
            $rate = (string) ($rates[$class['bucket']] ?? 0);
            $required = $basis->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp);
            $totalBasis = $totalBasis->plus($basis);
            $totalRequired = $totalRequired->plus($required);
            $rows[] = [
                'loan_id' => $loan->id, 'branch_id' => $loan->branch_id,
                'loan_number' => $loan->loan_number,
                'member' => $loan->member?->name, 'member_no' => $loan->member?->member_no,
                'samity' => $loan->member?->samity?->name,
                'officer' => $loan->member?->samity?->field_officer_id,
                'product' => $loan->product?->code,
                'bucket' => $class['bucket'], 'overdue_days' => $class['overdue_days'],
                'gross' => $this->m2($gross), 'savings' => $this->m2($savings),
                'basis' => $this->m2($basis), 'rate' => $rate,
                'required' => $this->m2($required),
            ];
        }

        return ['rows' => $rows, 'totals' => ['outstanding' => $this->m2($totalBasis), 'required' => $this->m2($totalRequired)]];
    }

    /**
     * Current org-wide 1120 provision balance (sum across branches).
     * 1120 is a contra-asset: ledgerBalance() reports it debit-normal
     * (negative), so the provision balance is its negation.
     */
    public function provisionBalance(): string
    {
        $ledger = Ledger::where('ledger_code', '1120')->firstOrFail();
        $total = BigDecimal::zero();
        foreach (LoanAccount::query()->distinct()->pluck('branch_id') as $branchId) {
            $total = $total->minus($this->posting->ledgerBalance($ledger, (int) $branchId));
        }

        return $this->m2($total);
    }

    /** One branch's provision balance in the natural (credit) direction. */
    private function branchProvisionBalance(Ledger $ledger, int $branchId): BigDecimal
    {
        return BigDecimal::zero()->minus($this->posting->ledgerBalance($ledger, $branchId));
    }

    /**
     * Post a run: per branch, journal the delta between that branch's
     * required total and its current 1120 balance.
     */
    public function postRun(array $filters, User $actor): LoanLossProvision
    {
        return DB::transaction(function () use ($filters, $actor) {
            $computed = $this->compute($filters);
            $ledger = Ledger::where('ledger_code', '1120')->firstOrFail();
            $expense = Ledger::where('ledger_code', '5030')->firstOrFail();
            $firstVoucherId = null;

            $byBranch = collect($computed['rows'])->groupBy('branch_id');
            foreach ($byBranch as $branchId => $rows) {
                $required = collect($rows)->reduce(fn ($s, $r) => $s->plus($r['required']), BigDecimal::zero());
                $current = $this->branchProvisionBalance($ledger, (int) $branchId);
                $delta = $required->minus($current);
                if ($delta->isZero()) {
                    continue;
                }
                $date = $this->businessDays->currentDate((int) $branchId);
                if ($delta->isPositive()) {
                    $voucher = $this->posting->post([
                        'branch_id' => (int) $branchId, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                        'transaction_date' => $date, 'comments' => 'LLP run '.$filters['report_date'],
                        'lines' => [['mapping_key' => 'loan.provision', 'amount' => $this->m2($delta)]],
                    ]);
                } else {
                    $amount = $this->m2($delta->abs());
                    $voucher = $this->posting->post([
                        'branch_id' => (int) $branchId, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                        'transaction_date' => $date, 'comments' => 'LLP run '.$filters['report_date'].' (release)',
                        'lines' => [
                            ['ledger_id' => $ledger->id, 'debit' => $amount, 'credit' => '0'],
                            ['ledger_id' => $expense->id, 'debit' => '0', 'credit' => $amount],
                        ],
                    ]);
                }
                $firstVoucherId ??= $voucher->id;
            }

            $required = BigDecimal::of($computed['totals']['required']);
            $actual = BigDecimal::of($this->provisionBalance());
            $coverage = $required->isPositive()
                ? $actual->multipliedBy(100)->dividedBy($required, 2, RoundingMode::HalfUp)
                : BigDecimal::of(100);

            return LoanLossProvision::create([
                'account_type' => ! empty($filters['account_type'])
                    ? \App\Models\LoanProduct::find($filters['account_type'])?->code
                    : null,
                'report_date' => $filters['report_date'],
                'outstanding' => $computed['totals']['outstanding'],
                'required_llp' => $computed['totals']['required'],
                'actual_llp' => $this->m2($actual),
                'coverage' => $this->m2($coverage),
                'voucher_id' => $firstVoucherId,
                'created_by' => $actor->id,
            ]);
        });
    }
}
