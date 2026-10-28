<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DepositAccount;
use App\Models\Employee;
use App\Models\LoanAccount;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard (Module 2 / Step 24) — management overview figures.
 *
 * Every figure is computed from the same source rows the module lists and
 * reports use, scoped in the DATA LAYER to the branches the user may
 * access (BranchAccessService), optionally narrowed to one branch.
 * "Today" / "this month" always mean the branch's current OPEN business
 * date (BusinessDayService), never the wall clock: each branch's figures
 * are first computed against its own open date, then summed.
 *
 * Deposit product grouping mirrors ReportService's registers:
 * savings = SAVINGS+CSAV, dps = DPS+LTS, fdr = FDR; any other admin-made
 * product code lands in "other" so the split always reconciles to total.
 */
class DashboardService
{
    private const SAVINGS_CODES = ['SAVINGS', 'CSAV'];
    private const DPS_CODES = ['DPS', 'LTS'];
    private const FDR_CODES = ['FDR'];
    private const LIVE_LOAN_STATUSES = ['Disbursed', 'Active'];

    public function __construct(
        private BusinessDayService $businessDays,
        private LoanMoneyService $money,
        private BranchAccessService $access,
    ) {}

    /**
     * @return array<string, mixed> Inertia props for the Dashboard page.
     */
    public function build(User $user, ?int $branchId, ?string $headlineDate = null): array
    {
        $scopeIds = $this->access->accessibleIds($user);
        if ($branchId !== null) {
            // The controller already 403s an inaccessible branch; intersect
            // anyway so the data layer can never leak another branch.
            $scopeIds = array_values(array_intersect($scopeIds, [$branchId]));
        }

        $branches = Branch::whereIn('id', $scopeIds)->orderBy('code')->get();

        // Per-branch open business dates (each branch runs its own day).
        $bizDates = [];
        foreach ($branches as $branch) {
            $bizDates[$branch->id] = $this->businessDays->currentDate($branch->id);
        }
        $asOn = $headlineDate
            ?? (count($bizDates) ? max(array_values($bizDates)) : now('Asia/Dhaka')->toDateString());

        return [
            'asOn' => $asOn,
            'kpis' => $this->kpis($branches, $bizDates),
            'trend' => $this->collectionTrend($branches, $bizDates),
            'samityRows' => $this->samityRows($branches, $bizDates),
            'officerRows' => $this->officerRows($branches, $bizDates),
        ];
    }

    // ---- KPI cards -------------------------------------------------------

    /** @param  \Illuminate\Support\Collection<int, Branch>  $branches */
    private function kpis($branches, array $bizDates): array
    {
        $members = ['total' => 0, 'active' => 0, 'new_month' => 0, 'closed' => 0];
        $samities = ['total' => 0, 'active' => 0];
        $deposit = ['accounts' => 0, 'savings' => BigDecimal::zero(), 'dps' => BigDecimal::zero(),
            'fdr' => BigDecimal::zero(), 'other' => BigDecimal::zero(), 'total' => BigDecimal::zero()];
        $loan = ['disbursed_count' => 0, 'disbursed_amount' => BigDecimal::zero(), 'active' => 0,
            'outstanding' => BigDecimal::zero(), 'overdue_amount' => BigDecimal::zero(), 'overdue_loans' => 0];
        $collection = ['today' => BigDecimal::zero(), 'today_loan' => BigDecimal::zero(),
            'today_deposit' => BigDecimal::zero(), 'month' => BigDecimal::zero()];

        foreach ($branches as $branch) {
            $biz = $bizDates[$branch->id];
            $monthStart = substr($biz, 0, 8).'01';

            $members['total'] += Member::where('branch_id', $branch->id)->count();
            $members['active'] += Member::where('branch_id', $branch->id)->where('status', 'Active')->count();
            $members['closed'] += Member::where('branch_id', $branch->id)->where('status', 'Closed')->count();
            $members['new_month'] += Member::where('branch_id', $branch->id)
                ->whereBetween('open_date', [$monthStart, $biz])->count();

            $samities['total'] += Samity::where('branch_id', $branch->id)->count();
            $samities['active'] += Samity::where('branch_id', $branch->id)->where('status', 'Active')->count();

            $byCode = DepositAccount::where('deposit_accounts.branch_id', $branch->id)
                ->where('deposit_accounts.is_active', true)
                ->whereNull('deposit_accounts.closing_date')
                ->join('deposit_products', 'deposit_products.id', '=', 'deposit_accounts.deposit_product_id')
                ->groupBy('deposit_products.code')
                ->selectRaw('deposit_products.code AS code, COUNT(*) AS accounts, COALESCE(SUM(deposit_accounts.current_balance), 0) AS balance')
                ->get();
            foreach ($byCode as $row) {
                $deposit['accounts'] += (int) $row->accounts;
                $deposit[$this->depositGroup((string) $row->code)] = $deposit[$this->depositGroup((string) $row->code)]
                    ->plus((string) $row->balance);
                $deposit['total'] = $deposit['total']->plus((string) $row->balance);
            }

            $loans = LoanAccount::where('branch_id', $branch->id)->get();
            foreach ($loans as $l) {
                if ($l->disbursed_at !== null) {
                    $loan['disbursed_count']++;
                    $loan['disbursed_amount'] = $loan['disbursed_amount']->plus((string) $l->loan_amount);
                }
                if (in_array($l->status, self::LIVE_LOAN_STATUSES, true)) {
                    $loan['active']++;
                    $loan['outstanding'] = $loan['outstanding']
                        ->plus($this->money->outstanding($l)['total']);
                }
            }
            $overdue = $this->overdueFor($branch->id, $biz);
            $loan['overdue_amount'] = $loan['overdue_amount']->plus($overdue['amount']);
            $loan['overdue_loans'] += $overdue['loans'];

            // Collection = money actually received, the DCR definition
            // (see receivedSums): batch postings and counter receives
            // both land in the same receive tables.
            $todaySrc = $this->receivedSums($branch->id, $biz, $biz);
            $collection['today'] = $collection['today']->plus($todaySrc['deposit'])->plus($todaySrc['loan']);
            $collection['today_loan'] = $collection['today_loan']->plus($todaySrc['loan']);
            $collection['today_deposit'] = $collection['today_deposit']->plus($todaySrc['deposit']);
            $monthSrc = $this->receivedSums($branch->id, $monthStart, $biz);
            $collection['month'] = $collection['month']->plus($monthSrc['deposit'])->plus($monthSrc['loan']);
        }

        return [
            'members' => $members,
            'samities' => $samities,
            'deposit' => [
                'accounts' => $deposit['accounts'],
                'savings' => $this->m2($deposit['savings']),
                'dps' => $this->m2($deposit['dps']),
                'fdr' => $this->m2($deposit['fdr']),
                'other' => $this->m2($deposit['other']),
                'total' => $this->m2($deposit['total']),
            ],
            'loan' => [
                'disbursed_count' => $loan['disbursed_count'],
                'disbursed_amount' => $this->m2($loan['disbursed_amount']),
                'active' => $loan['active'],
                'outstanding' => $this->m2($loan['outstanding']),
                'overdue_amount' => $this->m2($loan['overdue_amount']),
                'overdue_loans' => $loan['overdue_loans'],
            ],
            'collection' => [
                'today' => $this->m2($collection['today']),
                'today_loan' => $this->m2($collection['today_loan']),
                'today_deposit' => $this->m2($collection['today_deposit']),
                'month' => $this->m2($collection['month']),
            ],
        ];
    }

    /** Overdue = unpaid schedule rows due BEFORE the branch business date. */
    private function overdueFor(int $branchId, string $bizDate): array
    {
        $rows = DB::table('loan_schedules')
            ->join('loan_accounts', 'loan_accounts.id', '=', 'loan_schedules.loan_account_id')
            ->where('loan_accounts.branch_id', $branchId)
            ->whereIn('loan_accounts.status', self::LIVE_LOAN_STATUSES)
            ->whereNull('loan_schedules.superseded_by')
            ->where('loan_schedules.due_date', '<', $bizDate)
            ->where('loan_schedules.status', '!=', 'Paid')
            ->selectRaw('loan_schedules.loan_account_id AS loan_id, (loan_schedules.principal_due - loan_schedules.paid_principal) + (loan_schedules.profit_due - loan_schedules.paid_profit) AS remaining')
            ->get();

        $amount = BigDecimal::zero();
        $loanIds = [];
        foreach ($rows as $row) {
            $amount = $amount->plus((string) $row->remaining);
            $loanIds[$row->loan_id] = true;
        }

        return ['amount' => $amount, 'loans' => count($loanIds)];
    }

    // ---- Charts ----------------------------------------------------------

    /**
     * Posted collection per business date over the last 30 business days
     * (window ends at the latest open business date in scope).
     */
    private function collectionTrend($branches, array $bizDates): array
    {
        if ($branches->isEmpty()) {
            return [];
        }
        $branchIds = $branches->pluck('id')->all();
        $end = max(array_values($bizDates));
        $start = (new \DateTimeImmutable($end))->modify('-29 days')->format('Y-m-d');

        $dates = DB::table('business_days')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$start, $end])
            ->distinct()->orderBy('business_date')->pluck('business_date')
            ->map(fn ($d) => (string) $d)->all();
        if ($dates === []) {
            $dates = [$end];
        }

        $sums = [];
        $depByDate = DB::table('deposit_transactions')
            ->join('members', 'members.id', '=', 'deposit_transactions.member_id')
            ->whereIn('members.branch_id', $branchIds)
            ->where('deposit_transactions.tran_type', 'Receive')
            ->whereNull('deposit_transactions.cancel_at')
            ->whereBetween('deposit_transactions.business_date', [$start, $end])
            ->groupBy('deposit_transactions.business_date')
            ->selectRaw('deposit_transactions.business_date AS d, COALESCE(SUM(deposit_transactions.amount), 0) AS t')
            ->pluck('t', 'd');
        $loanByDate = DB::table('loan_receives')
            ->join('members', 'members.id', '=', 'loan_receives.member_id')
            ->whereIn('members.branch_id', $branchIds)
            ->whereNull('loan_receives.cancel_at')
            ->whereBetween('loan_receives.business_date', [$start, $end])
            ->groupBy('loan_receives.business_date')
            ->selectRaw('loan_receives.business_date AS d, COALESCE(SUM(loan_receives.installment_amount), 0) AS t')
            ->pluck('t', 'd');
        foreach ([$depByDate, $loanByDate] as $map) {
            foreach ($map as $d => $t) {
                $sums[$d] = ($sums[$d] ?? BigDecimal::zero())->plus((string) $t);
            }
        }

        return array_map(fn ($d) => [
            'date' => $d,
            'total' => $this->m2($sums[$d] ?? 0),
        ], $dates);
    }

    // ---- Summary tables ----------------------------------------------------

    private function samityRows($branches, array $bizDates): array
    {
        $rows = [];
        foreach ($branches as $branch) {
            $biz = $bizDates[$branch->id];
            $monthStart = substr($biz, 0, 8).'01';
            $samities = Samity::where('branch_id', $branch->id)->where('status', 'Active')
                ->orderBy('samity_code')->get();
            foreach ($samities as $samity) {
                $memberIds = Member::where('samity_id', $samity->id)->pluck('id');
                $savings = DepositAccount::whereIn('member_id', $memberIds)
                    ->where('is_active', true)->whereNull('closing_date')->sum('current_balance');
                $outstanding = BigDecimal::zero();
                $loans = LoanAccount::whereIn('member_id', $memberIds)
                    ->whereIn('status', self::LIVE_LOAN_STATUSES)->get();
                foreach ($loans as $l) {
                    $outstanding = $outstanding->plus($this->money->outstanding($l)['total']);
                }
                $src = $this->receivedSums($branch->id, $monthStart, $biz, $samity->id);
                $collection = bcadd($src['deposit'], $src['loan'], 2);
                $rows[] = [
                    'code' => $samity->samity_code,
                    'name' => $samity->name,
                    'branch_id' => $branch->id,
                    'officer_id' => $samity->field_officer_id,
                    'members' => Member::where('samity_id', $samity->id)->where('status', 'Active')->count(),
                    'savings' => $this->m2($savings),
                    'outstanding' => $this->m2($outstanding),
                    'collection' => $this->m2($collection),
                ];
            }
        }

        return $rows;
    }

    private function officerRows($branches, array $bizDates): array
    {
        $grouped = [];
        foreach ($this->samityRows($branches, $bizDates) as $row) {
            $key = $row['officer_id'] ?? 0;
            $grouped[$key] ??= ['officer_id' => $row['officer_id'], 'samities' => 0, 'members' => 0,
                'savings' => BigDecimal::zero(), 'outstanding' => BigDecimal::zero(), 'collection' => BigDecimal::zero()];
            $grouped[$key]['samities']++;
            $grouped[$key]['members'] += $row['members'];
            $grouped[$key]['savings'] = $grouped[$key]['savings']->plus($row['savings']);
            $grouped[$key]['outstanding'] = $grouped[$key]['outstanding']->plus($row['outstanding']);
            $grouped[$key]['collection'] = $grouped[$key]['collection']->plus($row['collection']);
        }

        $employees = Employee::whereIn('id', array_filter(array_keys($grouped)))->get()->keyBy('id');
        $rows = [];
        foreach ($grouped as $g) {
            $emp = $g['officer_id'] ? ($employees[$g['officer_id']] ?? null) : null;
            $rows[] = [
                'officer' => $emp ? trim(($emp->employee_code ?? '').' — '.($emp->name ?? ''), ' —') : null,
                'samities' => $g['samities'],
                'members' => $g['members'],
                'savings' => $this->m2($g['savings']),
                'outstanding' => $this->m2($g['outstanding']),
                'collection' => $this->m2($g['collection']),
            ];
        }
        usort($rows, fn ($a, $b) => ($a['officer'] === null) <=> ($b['officer'] === null));

        return $rows;
    }

    /**
     * Money received in a branch over a business-date range, split by
     * engine — the same definition as the Collection DCR report:
     * deposit receives (deposit_transactions, tran_type Receive) plus
     * loan receives (loan_receives), cancellations excluded. Sheet /
     * member collection postings write into these same tables, so the
     * dashboard, the DCR and the receive lists always agree.
     *
     * @return array{deposit: string, loan: string}
     */
    private function receivedSums(int $branchId, string $from, string $to, ?int $samityId = null): array
    {
        $deposit = DB::table('deposit_transactions')
            ->join('members', 'members.id', '=', 'deposit_transactions.member_id')
            ->where('members.branch_id', $branchId)
            ->when($samityId !== null, fn ($q) => $q->where('members.samity_id', $samityId))
            ->where('deposit_transactions.tran_type', 'Receive')
            ->whereNull('deposit_transactions.cancel_at')
            ->whereBetween('deposit_transactions.business_date', [$from, $to])
            ->sum('deposit_transactions.amount');
        $loan = DB::table('loan_receives')
            ->join('members', 'members.id', '=', 'loan_receives.member_id')
            ->where('members.branch_id', $branchId)
            ->when($samityId !== null, fn ($q) => $q->where('members.samity_id', $samityId))
            ->whereNull('loan_receives.cancel_at')
            ->whereBetween('loan_receives.business_date', [$from, $to])
            ->sum('loan_receives.installment_amount');

        return ['deposit' => $this->m2($deposit), 'loan' => $this->m2($loan)];
    }

    // ---- helpers -----------------------------------------------------------

    private function depositGroup(string $code): string
    {
        return match (true) {
            in_array($code, self::SAVINGS_CODES, true) => 'savings',
            in_array($code, self::DPS_CODES, true) => 'dps',
            in_array($code, self::FDR_CODES, true) => 'fdr',
            default => 'other',
        };
    }

    private function m2(BigDecimal|string|int|float $v): string
    {
        return (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HalfUp);
    }
}
