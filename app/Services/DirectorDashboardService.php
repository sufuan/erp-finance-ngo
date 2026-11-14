<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DepositAccount;
use App\Models\LoanAccount;
use App\Models\Member;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Director Dashboard (client order, 2026-11-14) — the পরিচালক's
 * view-only consolidated overview: big basic figures only, today /
 * this month / overall, all branches combined plus a per-branch table.
 *
 * SCOPE: the director.view permission IS the org-wide grant (the
 * Director oversees every branch, like Super Admin's all-branch
 * visibility), so figures cover every ACTIVE branch regardless of the
 * viewer's branch assignments.
 *
 * DEFINITIONS — deliberately identical to the existing reports, so
 * Director == Dashboard == DCR:
 *  - collections: CollectionReportService's DCR definition — money
 *    actually received (deposit_transactions Receive + loan_receives,
 *    cancellations excluded), per branch against its own OPEN business
 *    date (BusinessDayService), exactly as DashboardService::receivedSums;
 *  - due today: loan_schedules due on the branch business date on live
 *    loans, unpaid part = total_due − paid_principal − paid_profit
 *    (floored at 0 per row; same remaining-math as
 *    LoanMoneyService::todaysDue / DashboardService::overdueFor);
 *  - disbursed: LoanAccount.disbursed_at date (the disbursement
 *    register's source), amount = loan_amount;
 *  - income / expense: AccountReportService::incomeExpense totals;
 *  - outstanding: LoanMoneyService::outstanding totals on live loans;
 *  - deposit balance: current_balance of active, non-closed deposit
 *    accounts (DashboardService's deposit total).
 */
class DirectorDashboardService
{
    private const LIVE_LOAN_STATUSES = ['Disbursed', 'Active'];

    public function __construct(
        private BusinessDayService $businessDays,
        private LoanMoneyService $money,
        private AccountReportService $accounts,
    ) {}

    /** @return array<string, mixed> Inertia props for the Director page. */
    public function build(): array
    {
        $branches = Branch::where('status', 'Active')->orderBy('code')->get();

        $rows = [];
        $asOn = null;
        foreach ($branches as $branch) {
            $row = $this->branchFigures($branch);
            $rows[] = $row;
            $asOn = $asOn === null || $row['business_date'] > $asOn ? $row['business_date'] : $asOn;
        }

        if ($rows === []) {
            $zero = ['deposit_collection' => '0.00', 'loan_collection' => '0.00',
                'disbursed' => '0.00', 'disbursed_count' => 0, 'new_members' => 0,
                'income' => '0.00', 'expense' => '0.00'];

            return [
                'asOn' => $asOn ?? now('Asia/Dhaka')->toDateString(),
                'today' => $zero + ['due_today' => '0.00'],
                'month' => $zero,
                'totals' => ['members' => 0, 'active_loans' => 0, 'outstanding' => '0.00', 'deposit_balance' => '0.00'],
                'branches' => [],
            ];
        }

        return [
            'asOn' => $asOn ?? now('Asia/Dhaka')->toDateString(),
            'today' => $this->sumKey($rows, 'today'),
            'month' => $this->sumKey($rows, 'month'),
            'totals' => $this->sumKey($rows, 'totals'),
            'branches' => $rows,
        ];
    }

    /** One branch's figures, all against its own open business date. */
    private function branchFigures(Branch $branch): array
    {
        $biz = $this->businessDays->currentDate($branch->id);
        $monthStart = substr($biz, 0, 8).'01';

        $todaySrc = $this->receivedSums($branch->id, $biz, $biz);
        $monthSrc = $this->receivedSums($branch->id, $monthStart, $biz);
        $incomeToday = $this->accounts->incomeExpense([$branch->id], $biz, $biz);
        $incomeMonth = $this->accounts->incomeExpense([$branch->id], $monthStart, $biz);

        $disbursedToday = LoanAccount::where('branch_id', $branch->id)
            ->whereNotNull('disbursed_at')->whereDate('disbursed_at', $biz);
        $disbursedMonth = LoanAccount::where('branch_id', $branch->id)
            ->whereNotNull('disbursed_at')
            ->whereDate('disbursed_at', '>=', $monthStart)->whereDate('disbursed_at', '<=', $biz);

        $liveLoans = LoanAccount::where('branch_id', $branch->id)
            ->whereIn('status', self::LIVE_LOAN_STATUSES)->get();
        $outstanding = BigDecimal::zero();
        foreach ($liveLoans as $loan) {
            $outstanding = $outstanding->plus($this->money->outstanding($loan)['total']);
        }

        return [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'business_date' => $biz,
            'today' => [
                'deposit_collection' => $todaySrc['deposit'],
                'loan_collection' => $todaySrc['loan'],
                'due_today' => $this->dueToday($branch->id, $biz),
                'disbursed' => $this->m2($disbursedToday->sum('loan_amount')),
                'disbursed_count' => (clone $disbursedToday)->count(),
                'new_members' => Member::where('branch_id', $branch->id)->where('open_date', $biz)->count(),
                'income' => $incomeToday['total_income'],
                'expense' => $incomeToday['total_expense'],
            ],
            'month' => [
                'deposit_collection' => $monthSrc['deposit'],
                'loan_collection' => $monthSrc['loan'],
                'disbursed' => $this->m2($disbursedMonth->sum('loan_amount')),
                'disbursed_count' => (clone $disbursedMonth)->count(),
                'new_members' => Member::where('branch_id', $branch->id)
                    ->whereBetween('open_date', [$monthStart, $biz])->count(),
                'income' => $incomeMonth['total_income'],
                'expense' => $incomeMonth['total_expense'],
            ],
            'totals' => [
                'members' => Member::where('branch_id', $branch->id)->where('status', 'Active')->count(),
                'active_loans' => $liveLoans->count(),
                'outstanding' => $this->m2($outstanding),
                'deposit_balance' => $this->m2(DepositAccount::where('branch_id', $branch->id)
                    ->where('is_active', true)->whereNull('closing_date')->sum('current_balance')),
            ],
        ];
    }

    /** Installments due on the business date, unpaid part (DCR-style remaining). */
    private function dueToday(int $branchId, string $biz): string
    {
        $due = DB::table('loan_schedules')
            ->join('loan_accounts', 'loan_accounts.id', '=', 'loan_schedules.loan_account_id')
            ->where('loan_accounts.branch_id', $branchId)
            ->whereIn('loan_accounts.status', self::LIVE_LOAN_STATUSES)
            ->whereNull('loan_schedules.superseded_by')
            ->where('loan_schedules.due_date', $biz)
            ->where('loan_schedules.status', '!=', 'Paid')
            ->selectRaw('COALESCE(SUM(GREATEST(loan_schedules.total_due - loan_schedules.paid_principal - loan_schedules.paid_profit, 0)), 0) AS due')
            ->value('due');

        return $this->m2($due ?? 0);
    }

    /**
     * Money received in a branch over a business-date range — the DCR
     * definition, identical to DashboardService::receivedSums.
     *
     * @return array{deposit: string, loan: string}
     */
    private function receivedSums(int $branchId, string $from, string $to): array
    {
        $deposit = DB::table('deposit_transactions')
            ->join('members', 'members.id', '=', 'deposit_transactions.member_id')
            ->where('members.branch_id', $branchId)
            ->where('deposit_transactions.tran_type', 'Receive')
            ->whereNull('deposit_transactions.cancel_at')
            ->whereBetween('deposit_transactions.business_date', [$from, $to])
            ->sum('deposit_transactions.amount');
        $loan = DB::table('loan_receives')
            ->join('members', 'members.id', '=', 'loan_receives.member_id')
            ->where('members.branch_id', $branchId)
            ->whereNull('loan_receives.cancel_at')
            ->whereBetween('loan_receives.business_date', [$from, $to])
            ->sum('loan_receives.installment_amount');

        return ['deposit' => $this->m2($deposit), 'loan' => $this->m2($loan)];
    }

    /** Sum one figure-group ('today' | 'month' | 'totals') across branch rows. */
    private function sumKey(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row[$key] as $field => $value) {
                if (is_int($value)) {
                    $out[$field] = ($out[$field] ?? 0) + $value;
                } else {
                    $out[$field] = $this->m2(BigDecimal::of($out[$field] ?? '0')->plus((string) $value));
                }
            }
        }

        return $out;
    }

    private function m2(BigDecimal|string|int|float $v): string
    {
        return (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HalfUp);
    }
}
