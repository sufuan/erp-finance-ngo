<?php

namespace App\Services;

use App\Models\CollectionLine;
use App\Models\DepositAccount;
use App\Models\DepositTransaction;
use App\Models\Employee;
use App\Models\LoanAccount;
use App\Models\LoanReceive;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Steps 22–23 — Collection sheets, reports, MIS/POMIS and balance
 * reports on the same registry pattern as ReportService (Step 14):
 * {key, title, filters, pdf_view} + run() → columns/rows/total_row.
 *
 * The controller injects two internal filters: `_branch_ids` (the
 * caller's accessible branches — every query is branch-scoped) and
 * `_business_date` (the active branch's open business day).
 *
 * Data sources are the canonical ones: deposit_transactions,
 * loan_receives (+ schedules), collection batches/lines, and the
 * cached account balances that the engines maintain.
 */
class CollectionReportService
{
    public function __construct(private CollectionService $collections) {}

    /** @return array<string, array{title: string, group: string, filters: string[], pdf_view: string}> */
    public function registry(): array
    {
        $period = ['samity_id', 'employee_id', 'member', 'from_date', 'to_date'];
        $sheet = ['samity_id', 'employee_id', 'report_date', 'sheet_type'];

        return [
            // Collection sheets (Step 22)
            'collection_sheet' => ['title' => 'collection_sheet', 'group' => 'sheets', 'filters' => $sheet, 'pdf_view' => 'reports.generic'],
            'deposit_collection_sheet' => ['title' => 'deposit_collection_sheet', 'group' => 'sheets', 'filters' => $sheet, 'pdf_view' => 'reports.generic'],
            'loan_collection_sheet' => ['title' => 'loan_collection_sheet', 'group' => 'sheets', 'filters' => $sheet, 'pdf_view' => 'reports.generic'],
            'loan_collection_sheet_two' => ['title' => 'loan_collection_sheet_two', 'group' => 'sheets', 'filters' => $sheet, 'pdf_view' => 'reports.generic'],
            // Collection reports (Step 22)
            'collection_details' => ['title' => 'collection_details_report', 'group' => 'reports', 'filters' => $period, 'pdf_view' => 'reports.generic'],
            'collection_summary' => ['title' => 'collection_summary_report', 'group' => 'reports', 'filters' => $period, 'pdf_view' => 'reports.generic'],
            'dcr_report' => ['title' => 'dcr_report', 'group' => 'reports', 'filters' => $period, 'pdf_view' => 'reports.generic'],
            'loan_collection_details' => ['title' => 'loan_collection_details', 'group' => 'reports', 'filters' => [...$period, 'reporting_type'], 'pdf_view' => 'reports.generic'],
            'deposit_loan_collection_summary' => ['title' => 'deposit_loan_collection_summary', 'group' => 'reports', 'filters' => [...$period, 'report_group'], 'pdf_view' => 'reports.generic'],
            // Performance & monitoring (Step 22)
            'field_officers_report' => ['title' => 'field_officers_report', 'group' => 'performance', 'filters' => $period, 'pdf_view' => 'reports.generic'],
            'periodical_progress' => ['title' => 'periodical_progress_report', 'group' => 'performance', 'filters' => ['from_date', 'to_date'], 'pdf_view' => 'reports.generic'],
            'target_vs_achievement' => ['title' => 'target_vs_achievement', 'group' => 'performance', 'filters' => $period, 'pdf_view' => 'reports.generic'],
            'collection_topsheet' => ['title' => 'collection_topsheet', 'group' => 'performance', 'filters' => $period, 'pdf_view' => 'reports.generic'],
            // Branch summary (Step 22)
            'monthly_saving_progress' => ['title' => 'monthly_saving_progress', 'group' => 'branch', 'filters' => ['year'], 'pdf_view' => 'reports.generic'],
            'monthly_loan_progress' => ['title' => 'monthly_loan_progress', 'group' => 'branch', 'filters' => ['year'], 'pdf_view' => 'reports.generic'],
            'statements_summary' => ['title' => 'collection_statements_summary', 'group' => 'branch', 'filters' => $period, 'pdf_view' => 'reports.generic'],
            'mis_topsheet' => ['title' => 'mis_topsheet', 'group' => 'branch', 'filters' => ['report_date'], 'pdf_view' => 'reports.generic'],
            'mis_progress' => ['title' => 'mis_progress', 'group' => 'branch', 'filters' => ['from_date', 'to_date'], 'pdf_view' => 'reports.generic'],
            // POMIS (Step 22)
            'pomis_1' => ['title' => 'pomis_1', 'group' => 'pomis', 'filters' => ['samity_id', 'employee_id', 'report_date'], 'pdf_view' => 'reports.generic'],
            'pomis_2' => ['title' => 'pomis_2', 'group' => 'pomis', 'filters' => ['from_date', 'to_date'], 'pdf_view' => 'reports.generic'],
            'pomis_3' => ['title' => 'pomis_3', 'group' => 'pomis', 'filters' => ['from_date', 'to_date'], 'pdf_view' => 'reports.generic'],
            'pomis_4' => ['title' => 'pomis_4', 'group' => 'pomis', 'filters' => ['report_date'], 'pdf_view' => 'reports.generic'],
            // Balance reports (Step 23)
            'member_balance' => ['title' => 'member_balance_report', 'group' => 'balance', 'filters' => ['samity_id', 'employee_id', 'member'], 'pdf_view' => 'reports.generic'],
            'samity_balance' => ['title' => 'samity_wise_balance_report', 'group' => 'balance', 'filters' => ['employee_id'], 'pdf_view' => 'reports.generic'],
            'officer_balance' => ['title' => 'field_officer_balance_outstanding', 'group' => 'balance', 'filters' => [], 'pdf_view' => 'reports.generic'],
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->registry()[$key]);
    }

    /** @return array{columns: string[], rows: array<int, array<int, string>>, total_row: ?array} */
    public function run(string $key, array $filters): array
    {
        return match ($key) {
            'collection_sheet' => $this->sheet($filters, 'all'),
            'deposit_collection_sheet' => $this->sheet($filters, 'deposit'),
            'loan_collection_sheet', 'loan_collection_sheet_two' => $this->sheet($filters, 'loan'),
            'collection_details' => $this->collectionDetails($filters),
            'collection_summary' => $this->collectionSummary($filters),
            'dcr_report' => $this->dcr($filters),
            'loan_collection_details' => $this->loanCollectionDetails($filters),
            'deposit_loan_collection_summary' => $this->depositLoanSummary($filters),
            'field_officers_report' => $this->fieldOfficers($filters),
            'periodical_progress' => $this->progress($filters, false),
            'mis_progress' => $this->progress($filters, true),
            'target_vs_achievement' => $this->targetVsAchievement($filters),
            'collection_topsheet' => $this->topsheet($filters),
            'monthly_saving_progress' => $this->monthlySaving($filters),
            'monthly_loan_progress' => $this->monthlyLoan($filters),
            'statements_summary' => $this->statementsSummary($filters),
            'mis_topsheet' => $this->misTopsheet($filters),
            'pomis_1' => $this->pomis1($filters),
            'pomis_2' => $this->pomis2($filters),
            'pomis_3' => $this->pomis3($filters),
            'pomis_4' => $this->pomis4($filters),
            'member_balance' => $this->memberBalance($filters),
            'samity_balance' => $this->samityBalance($filters),
            'officer_balance' => $this->officerBalance($filters),
            default => ['columns' => [], 'rows' => [], 'total_row' => null],
        };
    }

    // ---- helpers ------------------------------------------------------------

    private function money(mixed $v): string
    {
        return (string) BigDecimal::of((string) ($v === null || $v === '' ? 0 : $v))->toScale(2, RoundingMode::HalfUp);
    }

    /** @return int[] */
    private function branchIds(array $filters): array
    {
        return array_map('intval', $filters['_branch_ids'] ?? []);
    }

    private function membersQuery(array $filters)
    {
        return Member::whereIn('branch_id', $this->branchIds($filters))
            ->when(! empty($filters['samity_id']), fn ($q) => $q->where('samity_id', (int) $filters['samity_id']))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->whereHas('samity', fn ($s) => $s->where('field_officer_id', (int) $filters['employee_id'])))
            ->when(! empty($filters['member']), fn ($q) => $q->where('member_no', trim((string) $filters['member'])));
    }

    private function period(array $filters): array
    {
        return [$filters['from_date'] ?? null, $filters['to_date'] ?? null];
    }

    private function applyPeriod($query, string $column, array $filters)
    {
        [$from, $to] = $this->period($filters);

        return $query->when($from, fn ($q) => $q->where($column, '>=', $from))
            ->when($to, fn ($q) => $q->where($column, '<=', $to));
    }

    // ---- sheets (Step 22) -----------------------------------------------------

    /**
     * Printable collection sheet: one row per member account of the
     * samity, receivable prefilled by the same rules as the posting
     * grid. sheet_type: blank (Received column empty) / target
     * (Received = target receivable) / with_data (Received = what the
     * day's posted collection lines actually took).
     */
    private function sheet(array $filters, string $kind): array
    {
        $date = $filters['report_date'] ?? $filters['_business_date'] ?? now()->toDateString();
        $type = $filters['sheet_type'] ?? 'blank';
        $columns = ['member_no', 'member_name', 'account', 'account_type', 'receivable', 'received'];
        $rows = [];
        $totalReceivable = BigDecimal::zero();
        $totalReceived = BigDecimal::zero();

        $members = $this->membersQuery($filters)->where('status', 'Active')->orderBy('member_no')->get();
        $receivedToday = CollectionLine::where('status', 'Posted')
            ->whereHas('batch', fn ($q) => $q->where('business_date', $date)->whereIn('branch_id', $this->branchIds($filters)))
            ->get()->groupBy(fn ($l) => $l->account_type.':'.$l->account_id)
            ->map(fn ($g) => $g->sum(fn ($l) => (float) $l->amount));

        foreach ($members as $member) {
            $grid = $this->collections->gridForMember($member)[0]['accounts'] ?? [];
            foreach ($grid as $account) {
                if ($account['account_type'] === 'share') {
                    continue; // sheets carry deposit + loan accounts
                }
                if ($kind !== 'all' && $account['account_type'] !== $kind) {
                    continue;
                }
                $received = match ($type) {
                    'target' => $account['receivable'],
                    'with_data' => $this->money($receivedToday[$account['account_type'].':'.$account['account_id']] ?? 0),
                    default => '',
                };
                $rows[] = [
                    $member->member_no, $member->name, $account['label'],
                    $account['product_label'], $account['receivable'], $received,
                ];
                $totalReceivable = $totalReceivable->plus($account['receivable']);
                if ($received !== '') {
                    $totalReceived = $totalReceived->plus($received);
                }
            }
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'total_row' => ['', '', '', __('total'), $this->money($totalReceivable), $type === 'blank' ? '' : $this->money($totalReceived)],
        ];
    }

    // ---- collection reports (Step 22) -----------------------------------------

    private function collectionDetails(array $filters): array
    {
        $memberIds = $this->membersQuery($filters)->pluck('id');
        $lines = CollectionLine::with(['member:id,member_no,name', 'batch.samity:id,name', 'batch.entryBy:id,name'])
            ->whereIn('member_id', $memberIds)
            ->whereHas('batch', fn ($q) => $this->applyPeriod($q, 'business_date', $filters))
            ->orderByDesc('id')->limit(1000)->get();

        $rows = [];
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $rows[] = [
                $line->batch->business_date?->toDateString() ?? '',
                $line->member->member_no, $line->member->name,
                $line->batch->samity?->name ?? '—',
                $line->account_type === 'deposit' ? ($line->deposit_product_code ?? 'Deposit') : ucfirst($line->account_type),
                (string) $line->account_id, $this->money($line->receivable),
                $line->status === 'Posted' ? $this->money($line->amount) : '0.00',
                $line->status, $line->batch->entryBy?->name ?? '',
            ];
            if ($line->status === 'Posted') {
                $total = $total->plus((string) $line->amount);
            }
        }

        return [
            'columns' => ['date', 'member_no', 'member_name', 'samity', 'account_type', 'account', 'receivable', 'received', 'status', 'entry_by'],
            'rows' => $rows,
            'total_row' => ['', '', '', '', '', __('total'), '', $this->money($total), '', ''],
        ];
    }

    private function collectionSummary(array $filters): array
    {
        $memberIds = $this->membersQuery($filters)->pluck('id');
        $byDate = CollectionLine::whereIn('member_id', $memberIds)->where('status', 'Posted')
            ->whereHas('batch', fn ($q) => $this->applyPeriod($q, 'business_date', $filters))
            ->get()->groupBy(fn ($l) => $l->batch->business_date?->toDateString() ?? '');

        $rows = [];
        $tLoan = $tDep = $tShare = $tAll = BigDecimal::zero();
        foreach ($byDate->sortKeys() as $date => $lines) {
            $loan = $lines->where('account_type', 'loan')->sum(fn ($l) => (float) $l->amount);
            $dep = $lines->where('account_type', 'deposit')->sum(fn ($l) => (float) $l->amount);
            $share = $lines->where('account_type', 'share')->sum(fn ($l) => (float) $l->amount);
            $rows[] = [$date, $this->money($loan), $this->money($dep), $this->money($share), $this->money($loan + $dep + $share)];
            $tLoan = $tLoan->plus($loan);
            $tDep = $tDep->plus($dep);
            $tShare = $tShare->plus($share);
            $tAll = $tAll->plus($loan + $dep + $share);
        }

        return [
            'columns' => ['date', 'loan_receive', 'deposit_receive', 'share_receive', 'total_receive'],
            'rows' => $rows,
            'total_row' => [__('total'), $this->money($tLoan), $this->money($tDep), $this->money($tShare), $this->money($tAll)],
        ];
    }

    private function dcr(array $filters): array
    {
        $samities = Samity::whereIn('branch_id', $this->branchIds($filters))
            ->when(! empty($filters['samity_id']), fn ($q) => $q->where('id', (int) $filters['samity_id']))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('field_officer_id', (int) $filters['employee_id']))
            ->orderBy('name')->get();
        $rows = [];
        $tDep = $tLoan = $tAll = BigDecimal::zero();
        foreach ($samities as $samity) {
            $dep = DepositTransaction::where('tran_type', 'Receive')->whereNull('cancel_at')
                ->whereIn('member_id', Member::where('samity_id', $samity->id)->pluck('id'));
            $loan = LoanReceive::whereNull('cancel_at')
                ->whereIn('member_id', Member::where('samity_id', $samity->id)->pluck('id'));
            $depTotal = $this->money($this->applyPeriod($dep, 'business_date', $filters)->sum('amount'));
            $loanTotal = $this->money($this->applyPeriod($loan, 'business_date', $filters)->sum('installment_amount'));
            $rows[] = [$samity->samity_code, $samity->name, $depTotal, $loanTotal, $this->money(BigDecimal::of($depTotal)->plus($loanTotal))];
            $tDep = $tDep->plus($depTotal);
            $tLoan = $tLoan->plus($loanTotal);
            $tAll = $tAll->plus($depTotal)->plus($loanTotal);
        }

        return [
            'columns' => ['samity_code', 'samity', 'deposit_receive', 'loan_receive', 'total_receive'],
            'rows' => $rows,
            'total_row' => ['', __('total'), $this->money($tDep), $this->money($tLoan), $this->money($tAll)],
        ];
    }

    private function loanCollectionDetails(array $filters): array
    {
        $memberIds = $this->membersQuery($filters)->pluck('id');
        $today = $filters['_business_date'] ?? now()->toDateString();
        $receives = LoanReceive::with(['member:id,member_no,name', 'loanAccount:id,loan_number'])
            ->whereIn('member_id', $memberIds)->whereNull('cancel_at');
        $receives = $this->applyPeriod($receives, 'business_date', $filters)
            ->orderByDesc('id')->limit(1000)->get();

        $reportingType = $filters['reporting_type'] ?? 'all';
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($receives as $receive) {
            $loan = LoanAccount::with('schedules')->find($receive->loan_account_id);
            $category = 'Regular';
            if ($loan) {
                $dueToDate = BigDecimal::zero();
                $paidTotal = BigDecimal::zero();
                $overdue = false;
                foreach ($loan->schedules as $row) {
                    $paidTotal = $paidTotal->plus((string) $row->paid_principal)->plus((string) $row->paid_profit);
                    if ($row->due_date->toDateString() <= $today) {
                        $dueToDate = $dueToDate->plus((string) $row->total_due);
                        if ($row->status !== 'Paid' && $row->due_date->toDateString() < $today) {
                            $overdue = true;
                        }
                    }
                }
                $category = $overdue ? 'Overdue' : ($paidTotal->isGreaterThan($dueToDate) ? 'Advance' : 'Regular');
            }
            if ($reportingType !== 'all' && strtolower($category) !== $reportingType) {
                continue;
            }
            $rows[] = [
                $receive->business_date?->toDateString() ?? '',
                $receive->member->member_no, $receive->member->name,
                $receive->loanAccount?->loan_number ?? (string) $receive->loan_account_id,
                $this->money($receive->investment_amount), $this->money($receive->profit_amount),
                $this->money($receive->penalty_charge), $this->money($receive->installment_amount),
                $category,
            ];
            $total = $total->plus((string) $receive->installment_amount);
        }

        return [
            'columns' => ['date', 'member_no', 'member_name', 'loan_number', 'principal', 'profit', 'penalty', 'total_receive', 'reporting_type'],
            'rows' => $rows,
            'total_row' => ['', '', '', __('total'), '', '', '', $this->money($total), ''],
        ];
    }

    private function depositLoanSummary(array $filters): array
    {
        $group = $filters['report_group'] ?? 'center';
        // group => [label, member query scope]
        $groups = [];
        if ($group === 'branch') {
            foreach (DB::table('branches')->whereIn('id', $this->branchIds($filters))->orderBy('code')->get() as $branch) {
                $groups[$branch->code.' — '.$branch->name] = Member::where('branch_id', $branch->id)->pluck('id');
            }
        } elseif ($group === 'employee') {
            foreach (Employee::orderBy('name')->get() as $employee) {
                $samityIds = Samity::where('field_officer_id', $employee->id)->whereIn('branch_id', $this->branchIds($filters))->pluck('id');
                if ($samityIds->isNotEmpty()) {
                    $groups[$employee->employee_code.' — '.$employee->name] = Member::whereIn('samity_id', $samityIds)->pluck('id');
                }
            }
        } elseif ($group === 'member') {
            foreach ($this->membersQuery($filters)->orderBy('member_no')->limit(500)->get() as $member) {
                $groups[$member->member_no.' — '.$member->name] = collect([$member->id]);
            }
        } else { // center = samity
            foreach (Samity::whereIn('branch_id', $this->branchIds($filters))->orderBy('name')->get() as $samity) {
                $groups[$samity->samity_code.' — '.$samity->name] = Member::where('samity_id', $samity->id)->pluck('id');
            }
        }

        $rows = [];
        $tDep = $tLoan = $tAll = BigDecimal::zero();
        foreach ($groups as $label => $memberIds) {
            $dep = $this->money($this->applyPeriod(
                DepositTransaction::where('tran_type', 'Receive')->whereNull('cancel_at')->whereIn('member_id', $memberIds),
                'business_date', $filters)->sum('amount'));
            $loan = $this->money($this->applyPeriod(
                LoanReceive::whereNull('cancel_at')->whereIn('member_id', $memberIds),
                'business_date', $filters)->sum('installment_amount'));
            $rows[] = [$label, (string) $memberIds->count(), $dep, $loan, $this->money(BigDecimal::of($dep)->plus($loan))];
            $tDep = $tDep->plus($dep);
            $tLoan = $tLoan->plus($loan);
            $tAll = $tAll->plus($dep)->plus($loan);
        }

        return [
            'columns' => ['report_group', 'members', 'deposit_receive', 'loan_receive', 'total_receive'],
            'rows' => $rows,
            'total_row' => [__('total'), '', $this->money($tDep), $this->money($tLoan), $this->money($tAll)],
        ];
    }

    // ---- performance & monitoring (Step 22) --------------------------------------

    /** Per field officer: their samities' members, period dues and receipts. */
    private function fieldOfficers(array $filters): array
    {
        $rows = [];
        $employees = Employee::when(! empty($filters['employee_id']), fn ($q) => $q->where('id', (int) $filters['employee_id']))
            ->orderBy('name')->get();
        $tMembers = 0;
        $tDue = $tReceived = BigDecimal::zero();
        foreach ($employees as $employee) {
            $samityIds = Samity::where('field_officer_id', $employee->id)
                ->whereIn('branch_id', $this->branchIds($filters))->pluck('id');
            if ($samityIds->isEmpty()) {
                continue;
            }
            $memberIds = Member::whereIn('samity_id', $samityIds)->pluck('id');
            $due = $this->applyPeriod(
                LoanSchedule::whereIn('loan_account_id', LoanAccount::whereIn('member_id', $memberIds)->pluck('id')),
                'due_date', $filters)->sum('total_due');
            $received = $this->applyPeriod(
                LoanReceive::whereNull('cancel_at')->whereIn('member_id', $memberIds),
                'business_date', $filters)->sum('installment_amount');
            $depReceived = $this->applyPeriod(
                DepositTransaction::where('tran_type', 'Receive')->whereNull('cancel_at')->whereIn('member_id', $memberIds),
                'business_date', $filters)->sum('amount');
            $rate = BigDecimal::of((string) $due)->isPositive()
                ? (string) BigDecimal::of((string) $received)->dividedBy((string) $due, 4, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
                : '0.00';
            $rows[] = [
                $employee->employee_code, $employee->name, (string) $samityIds->count(),
                (string) $memberIds->count(), $this->money($due), $this->money($received),
                $this->money($depReceived), $rate.'%',
            ];
            $tMembers += $memberIds->count();
            $tDue = $tDue->plus((string) $due);
            $tReceived = $tReceived->plus((string) $received);
        }

        return [
            'columns' => ['employee_code', 'employee', 'samities', 'members', 'loan_due', 'loan_receive', 'deposit_receive', 'collection_rate'],
            'rows' => $rows,
            'total_row' => ['', __('total'), '', (string) $tMembers, $this->money($tDue), $this->money($tReceived), '', ''],
        ];
    }

    /** Periodical progress: one row per month in the range. */
    private function progress(array $filters, bool $cumulative): array
    {
        [$from, $to] = $this->period($filters);
        $from ??= now()->startOfYear()->toDateString();
        $to ??= $filters['_business_date'] ?? now()->toDateString();
        $branchIds = $this->branchIds($filters);
        $rows = [];
        $cursor = \Carbon\Carbon::parse($from)->startOfMonth();
        $end = \Carbon\Carbon::parse($to)->startOfMonth();
        $cumMembers = 0;
        $cumDeposit = $cumRecovered = BigDecimal::zero();
        while ($cursor->lessThanOrEqualTo($end)) {
            $monthStart = $cursor->toDateString();
            $monthEnd = $cursor->copy()->endOfMonth()->toDateString();
            $newMembers = Member::whereIn('branch_id', $branchIds)
                ->whereBetween('open_date', [$monthStart, $monthEnd])->count();
            $deposit = DepositTransaction::where('tran_type', 'Receive')->whereNull('cancel_at')
                ->whereIn('branch_id', $branchIds)->whereBetween('business_date', [$monthStart, $monthEnd])->sum('amount');
            $disbursed = LoanAccount::whereIn('branch_id', $branchIds)
                ->whereNotNull('disbursed_at')->whereBetween('opening_date', [$monthStart, $monthEnd])->sum('loan_amount');
            $recovered = LoanReceive::whereNull('cancel_at')
                ->whereIn('loan_account_id', LoanAccount::whereIn('branch_id', $branchIds)->pluck('id'))
                ->whereBetween('business_date', [$monthStart, $monthEnd])->sum('installment_amount');
            $cumMembers += $newMembers;
            $cumDeposit = $cumDeposit->plus((string) $deposit);
            $cumRecovered = $cumRecovered->plus((string) $recovered);
            $rows[] = [
                $cursor->format('M Y'),
                (string) ($cumulative ? $cumMembers : $newMembers),
                $this->money($cumulative ? $cumDeposit : $deposit),
                $this->money($disbursed),
                $this->money($cumulative ? $cumRecovered : $recovered),
            ];
            $cursor->addMonth();
        }

        return [
            'columns' => ['month', 'members', 'deposit_receive', 'loan_disbursed', 'loan_receive'],
            'rows' => $rows,
            'total_row' => null,
        ];
    }

    /**
     * Target = what the samity's members should pay (loan dues to date +
     * deposit installment amounts, via CollectionService receivables);
     * Achievement = collection lines actually posted in the period.
     */
    private function targetVsAchievement(array $filters): array
    {
        $samities = Samity::whereIn('branch_id', $this->branchIds($filters))
            ->when(! empty($filters['samity_id']), fn ($q) => $q->where('id', (int) $filters['samity_id']))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('field_officer_id', (int) $filters['employee_id']))
            ->orderBy('name')->get();
        $rows = [];
        $tTarget = $tAchieved = BigDecimal::zero();
        foreach ($samities as $samity) {
            $memberIds = Member::where('samity_id', $samity->id)->where('status', 'Active')->pluck('id')->all();
            $target = $this->collections->totalReceivableForMembers($memberIds);
            // Period filter applies on the batch business date.
            $achieved = $this->money(CollectionLine::where('status', 'Posted')->whereIn('member_id', $memberIds)
                ->whereHas('batch', fn ($q) => $this->applyPeriod($q, 'business_date', $filters))
                ->sum('amount'));
            $pct = BigDecimal::of($target)->isPositive()
                ? (string) BigDecimal::of($achieved)->dividedBy($target, 4, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
                : '0.00';
            $rows[] = [$samity->samity_code, $samity->name, $target, $achieved, $pct.'%'];
            $tTarget = $tTarget->plus($target);
            $tAchieved = $tAchieved->plus($achieved);
        }

        return [
            'columns' => ['samity_code', 'samity', 'target', 'achievement', 'achievement_percent'],
            'rows' => $rows,
            'total_row' => ['', __('total'), $this->money($tTarget), $this->money($tAchieved), ''],
        ];
    }

    /** Date-wise collection topsheet (all sources, incl. branch receives). */
    private function topsheet(array $filters): array
    {
        $memberIds = $this->membersQuery($filters)->pluck('id');
        $dates = $this->applyPeriod(
            DB::table('collection_batches')->whereIn('branch_id', $this->branchIds($filters))->where('status', 'Posted'),
            'business_date', $filters)
            ->select('business_date')->distinct()->orderBy('business_date')->pluck('business_date');
        $rows = [];
        $tDep = $tLoan = $tOther = $tAll = BigDecimal::zero();
        foreach ($dates as $date) {
            $dep = DepositTransaction::where('tran_type', 'Receive')->whereNull('cancel_at')
                ->whereIn('member_id', $memberIds)->where('business_date', $date)->sum('amount');
            $loan = LoanReceive::whereNull('cancel_at')->whereIn('member_id', $memberIds)
                ->where('business_date', $date)->sum('installment_amount');
            $other = \App\Models\OtherCollection::whereNull('cancel_at')
                ->whereIn('branch_id', $this->branchIds($filters))->where('business_date', $date)->sum('amount');
            $rows[] = [$date, $this->money($dep), $this->money($loan), $this->money($other), $this->money(BigDecimal::of((string) $dep)->plus((string) $loan)->plus((string) $other))];
            $tDep = $tDep->plus((string) $dep);
            $tLoan = $tLoan->plus((string) $loan);
            $tOther = $tOther->plus((string) $other);
            $tAll = $tAll->plus((string) $dep)->plus((string) $loan)->plus((string) $other);
        }

        return [
            'columns' => ['date', 'deposit_receive', 'loan_receive', 'other_collection', 'total_receive'],
            'rows' => $rows,
            'total_row' => [__('total'), $this->money($tDep), $this->money($tLoan), $this->money($tOther), $this->money($tAll)],
        ];
    }

    // ---- branch summary (Step 22) --------------------------------------------------

    private function monthlySaving(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);
        $branchIds = $this->branchIds($filters);
        $rows = [];
        $tIn = $tOut = BigDecimal::zero();
        $balance = BigDecimal::of((string) (DepositTransaction::whereIn('branch_id', $branchIds)
            ->whereNull('cancel_at')->where('business_date', '<', $year.'-01-01')
            ->selectRaw("SUM(CASE WHEN tran_type='Receive' THEN amount ELSE -amount END) as bal")->value('bal') ?? '0'));
        for ($m = 1; $m <= 12; $m++) {
            $start = sprintf('%04d-%02d-01', $year, $m);
            $end = date('Y-m-t', strtotime($start));
            $in = DepositTransaction::where('tran_type', 'Receive')->whereNull('cancel_at')
                ->whereIn('branch_id', $branchIds)->whereBetween('business_date', [$start, $end])->sum('amount');
            $out = DepositTransaction::where('tran_type', 'Payment')->whereNull('cancel_at')
                ->whereIn('branch_id', $branchIds)->whereBetween('business_date', [$start, $end])->sum('amount');
            $balance = $balance->plus((string) $in)->minus((string) $out);
            $rows[] = [date('M', strtotime($start)), $this->money($in), $this->money($out), $this->money($balance)];
            $tIn = $tIn->plus((string) $in);
            $tOut = $tOut->plus((string) $out);
        }

        return [
            'columns' => ['month', 'deposit_receive', 'deposit_payment', 'balance'],
            'rows' => $rows,
            'total_row' => [__('total'), $this->money($tIn), $this->money($tOut), $this->money($balance)],
        ];
    }

    private function monthlyLoan(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);
        $branchIds = $this->branchIds($filters);
        $loanIds = LoanAccount::whereIn('branch_id', $branchIds)->pluck('id');
        $rows = [];
        $tDisb = $tRec = BigDecimal::zero();
        $outstanding = BigDecimal::of((string) (LoanSchedule::whereIn('loan_account_id', $loanIds)
            ->where('due_date', '<', $year.'-01-01')
            ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0'));
        for ($m = 1; $m <= 12; $m++) {
            $start = sprintf('%04d-%02d-01', $year, $m);
            $end = date('Y-m-t', strtotime($start));
            $disb = LoanAccount::whereIn('branch_id', $branchIds)->whereNotNull('disbursed_at')
                ->whereBetween('opening_date', [$start, $end])->sum('loan_amount');
            $rec = LoanReceive::whereNull('cancel_at')->whereIn('loan_account_id', $loanIds)
                ->whereBetween('business_date', [$start, $end])->sum('installment_amount');
            $newDue = LoanSchedule::whereIn('loan_account_id', $loanIds)
                ->whereBetween('due_date', [$start, $end])->sum('total_due');
            $outstanding = $outstanding->plus((string) $newDue)->minus((string) $rec);
            $rows[] = [date('M', strtotime($start)), $this->money($disb), $this->money($rec), $this->money($outstanding)];
            $tDisb = $tDisb->plus((string) $disb);
            $tRec = $tRec->plus((string) $rec);
        }

        return [
            'columns' => ['month', 'loan_disbursed', 'loan_receive', 'loan_outstanding'],
            'rows' => $rows,
            'total_row' => [__('total'), $this->money($tDisb), $this->money($tRec), $this->money($outstanding)],
        ];
    }

    private function statementsSummary(array $filters): array
    {
        $rows = [];
        foreach (Samity::whereIn('branch_id', $this->branchIds($filters))->orderBy('name')->get() as $samity) {
            $memberIds = Member::where('samity_id', $samity->id)->pluck('id');
            $depositBalance = DepositAccount::whereIn('member_id', $memberIds)->where('is_active', true)->sum('current_balance');
            $loanOutstanding = LoanSchedule::whereIn('loan_account_id', LoanAccount::whereIn('member_id', $memberIds)->pluck('id'))
                ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0';
            $collected = CollectionLine::where('status', 'Posted')->whereIn('member_id', $memberIds)
                ->whereHas('batch', fn ($q) => $this->applyPeriod($q->whereIn('branch_id', $this->branchIds($filters)), 'business_date', $filters))
                ->sum('amount');
            $rows[] = [
                $samity->samity_code, $samity->name, (string) $memberIds->count(),
                $this->money($depositBalance), $this->money($loanOutstanding), $this->money($collected),
            ];
        }

        return [
            'columns' => ['samity_code', 'samity', 'members', 'deposit_balance', 'loan_outstanding', 'total_receive'],
            'rows' => $rows,
            'total_row' => null,
        ];
    }

    private function misTopsheet(array $filters): array
    {
        $branchIds = $this->branchIds($filters);
        $date = $filters['report_date'] ?? $filters['_business_date'] ?? now()->toDateString();
        $memberIds = Member::whereIn('branch_id', $branchIds)->pluck('id');
        $depositBalance = DepositAccount::whereIn('member_id', $memberIds)->where('is_active', true)->sum('current_balance');
        $share = Member::whereIn('branch_id', $branchIds)->sum('share_amount');
        $outstanding = LoanSchedule::whereIn('loan_account_id', LoanAccount::whereIn('branch_id', $branchIds)->pluck('id'))
            ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0';
        $dueToDate = LoanSchedule::whereIn('loan_account_id', LoanAccount::whereIn('branch_id', $branchIds)->pluck('id'))
            ->where('due_date', '<=', $date)->sum('total_due');
        $paidToDate = LoanSchedule::whereIn('loan_account_id', LoanAccount::whereIn('branch_id', $branchIds)->pluck('id'))
            ->selectRaw('SUM(paid_principal + paid_profit) as paid')->value('paid');
        $rate = BigDecimal::of((string) $dueToDate)->isPositive()
            ? (string) BigDecimal::of((string) $paidToDate)->dividedBy((string) $dueToDate, 4, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
            : '100.00';
        $overdue = LoanSchedule::whereIn('loan_account_id', LoanAccount::whereIn('branch_id', $branchIds)->pluck('id'))
            ->where('due_date', '<', $date)->where('status', '!=', 'Paid')
            ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0';
        $pairs = [
            [__('total_samities'), (string) Samity::whereIn('branch_id', $branchIds)->where('status', 'Active')->count()],
            [__('total_members'), (string) Member::whereIn('branch_id', $branchIds)->where('status', 'Active')->count()],
            [__('deposit_balance'), $this->money($depositBalance)],
            [__('share_balance'), $this->money($share)],
            [__('loan_outstanding'), $this->money($outstanding)],
            [__('loan_overdue'), $this->money($overdue)],
            [__('recovery_rate'), $rate.'%'],
            [__('report_date'), $date],
        ];

        return ['columns' => ['item', 'amount'], 'rows' => $pairs, 'total_row' => null];
    }

    // ---- POMIS (Step 22) -------------------------------------------------------------

    /** POMIS-1: samity, member and savings position. */
    private function pomis1(array $filters): array
    {
        $samities = Samity::whereIn('branch_id', $this->branchIds($filters))
            ->when(! empty($filters['samity_id']), fn ($q) => $q->where('id', (int) $filters['samity_id']))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('field_officer_id', (int) $filters['employee_id']))
            ->orderBy('name')->get();
        $rows = [];
        $tM = $tF = 0;
        $tSav = $tDps = $tFdr = $tShare = BigDecimal::zero();
        foreach ($samities as $samity) {
            $members = Member::where('samity_id', $samity->id)->where('status', 'Active')->get();
            $byProduct = ['SAVINGS' => BigDecimal::zero(), 'DPS' => BigDecimal::zero(), 'FDR' => BigDecimal::zero()];
            foreach (DepositAccount::with('product:id,code')->whereIn('member_id', $members->pluck('id'))->where('is_active', true)->get() as $account) {
                $code = $account->product?->code;
                $bucket = in_array($code, ['DPS', 'LTS'], true) ? 'DPS' : ($code === 'FDR' ? 'FDR' : 'SAVINGS');
                $byProduct[$bucket] = $byProduct[$bucket]->plus((string) $account->current_balance);
            }
            $shareSum = $members->sum(fn ($m) => (float) $m->share_amount);
            $rows[] = [
                $samity->samity_code, $samity->name,
                (string) $members->where('gender', 'Male')->count(),
                (string) $members->where('gender', 'Female')->count(),
                (string) $members->count(),
                $this->money($byProduct['SAVINGS']), $this->money($byProduct['DPS']),
                $this->money($byProduct['FDR']), $this->money($shareSum),
            ];
            $tM += $members->where('gender', 'Male')->count();
            $tF += $members->where('gender', 'Female')->count();
            $tSav = $tSav->plus($byProduct['SAVINGS']);
            $tDps = $tDps->plus($byProduct['DPS']);
            $tFdr = $tFdr->plus($byProduct['FDR']);
            $tShare = $tShare->plus($shareSum);
        }

        return [
            'columns' => ['samity_code', 'samity', 'male_members', 'female_members', 'total_members', 'savings_balance', 'dps_balance', 'fdr_balance', 'share_balance'],
            'rows' => $rows,
            'total_row' => ['', __('total'), (string) $tM, (string) $tF, (string) ($tM + $tF), $this->money($tSav), $this->money($tDps), $this->money($tFdr), $this->money($tShare)],
        ];
    }

    /** POMIS-2: statement of loan, per loan product. */
    private function pomis2(array $filters): array
    {
        $branchIds = $this->branchIds($filters);
        $rows = [];
        foreach (DB::table('loan_products')->orderBy('code')->get() as $product) {
            $loanIds = LoanAccount::where('loan_product_id', $product->id)->whereIn('branch_id', $branchIds)->pluck('id');
            if ($loanIds->isEmpty()) {
                continue;
            }
            $disbursed = LoanAccount::whereIn('id', $loanIds)->whereNotNull('disbursed_at')->sum('loan_amount');
            $recovered = $this->applyPeriod(LoanReceive::whereNull('cancel_at')->whereIn('loan_account_id', $loanIds), 'business_date', $filters)->sum('installment_amount');
            $outstanding = LoanSchedule::whereIn('loan_account_id', $loanIds)
                ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0';
            $rows[] = [
                $product->code, $product->name_en ?? $product->code,
                (string) LoanAccount::whereIn('id', $loanIds)->whereNotNull('disbursed_at')->count(),
                $this->money($disbursed), $this->money($recovered), $this->money($outstanding),
            ];
        }

        return [
            'columns' => ['product_code', 'loan_product', 'loan_count', 'loan_disbursed', 'loan_receive', 'loan_outstanding'],
            'rows' => $rows,
            'total_row' => null,
        ];
    }

    /** POMIS-3: loan recovery and dues for the period. */
    private function pomis3(array $filters): array
    {
        $branchIds = $this->branchIds($filters);
        $loanIds = LoanAccount::whereIn('branch_id', $branchIds)->pluck('id');
        $duePeriod = $this->applyPeriod(LoanSchedule::whereIn('loan_account_id', $loanIds), 'due_date', $filters)->sum('total_due');
        $recoveredPeriod = $this->applyPeriod(LoanReceive::whereNull('cancel_at')->whereIn('loan_account_id', $loanIds), 'business_date', $filters)->sum('installment_amount');
        $today = $filters['_business_date'] ?? now()->toDateString();
        $overdue = LoanSchedule::whereIn('loan_account_id', $loanIds)->where('due_date', '<', $today)
            ->where('status', '!=', 'Paid')
            ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0';
        $outstanding = LoanSchedule::whereIn('loan_account_id', $loanIds)
            ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0';
        $rate = BigDecimal::of((string) $duePeriod)->isPositive()
            ? (string) BigDecimal::of((string) $recoveredPeriod)->dividedBy((string) $duePeriod, 4, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
            : '0.00';
        $rows = [
            [__('due_in_period'), $this->money($duePeriod)],
            [__('recovered_in_period'), $this->money($recoveredPeriod)],
            [__('recovery_rate'), $rate.'%'],
            [__('loan_overdue'), $this->money($overdue)],
            [__('loan_outstanding'), $this->money($outstanding)],
        ];

        return ['columns' => ['item', 'amount'], 'rows' => $rows, 'total_row' => null];
    }

    /** POMIS-4: overdue classification by age of the oldest unpaid row. */
    private function pomis4(array $filters): array
    {
        $today = \Carbon\Carbon::parse($filters['report_date'] ?? $filters['_business_date'] ?? now()->toDateString());
        $buckets = [
            'current' => [0, 0, BigDecimal::zero()],
            'overdue_1_30' => [0, 0, BigDecimal::zero()],
            'overdue_31_90' => [0, 0, BigDecimal::zero()],
            'overdue_91_180' => [0, 0, BigDecimal::zero()],
            'overdue_181_365' => [0, 0, BigDecimal::zero()],
            'overdue_365_plus' => [0, 0, BigDecimal::zero()],
        ];
        $loans = LoanAccount::with('schedules')->whereIn('branch_id', $this->branchIds($filters))
            ->whereIn('status', ['Disbursed', 'Active'])->get();
        foreach ($loans as $loan) {
            $outstanding = BigDecimal::zero();
            $oldest = null;
            foreach ($loan->schedules as $row) {
                $owed = BigDecimal::of((string) $row->principal_due)->minus((string) $row->paid_principal)
                    ->plus(BigDecimal::of((string) $row->profit_due)->minus((string) $row->paid_profit));
                $outstanding = $outstanding->plus($owed);
                if ($row->status !== 'Paid' && $owed->isPositive() && $row->due_date->lessThan($today)) {
                    $days = $row->due_date->diffInDays($today);
                    $oldest = $oldest === null ? $days : max($oldest, $days);
                }
            }
            if (! $outstanding->isPositive()) {
                continue;
            }
            $key = match (true) {
                $oldest === null => 'current',
                $oldest <= 30 => 'overdue_1_30',
                $oldest <= 90 => 'overdue_31_90',
                $oldest <= 180 => 'overdue_91_180',
                $oldest <= 365 => 'overdue_181_365',
                default => 'overdue_365_plus',
            };
            $buckets[$key][0]++;
            $buckets[$key][2] = $buckets[$key][2]->plus($outstanding);
        }
        $rows = [];
        foreach ($buckets as $key => [$count, , $amount]) {
            $rows[] = [__($key), (string) $count, $this->money($amount)];
        }

        return ['columns' => ['classification', 'loan_count', 'loan_outstanding'], 'rows' => $rows, 'total_row' => null];
    }

    // ---- balance reports (Step 23) ------------------------------------------------------

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} savings, dps, fdr, share, loan outstanding */
    private function memberBalances(Member $member): array
    {
        $sav = $dps = $fdr = BigDecimal::zero();
        foreach (DepositAccount::with('product:id,code')->where('member_id', $member->id)->where('is_active', true)->get() as $account) {
            $code = $account->product?->code;
            if (in_array($code, ['DPS', 'LTS'], true)) {
                $dps = $dps->plus((string) $account->current_balance);
            } elseif ($code === 'FDR') {
                $fdr = $fdr->plus((string) $account->current_balance);
            } else {
                $sav = $sav->plus((string) $account->current_balance);
            }
        }
        $outstanding = LoanSchedule::whereIn('loan_account_id', LoanAccount::where('member_id', $member->id)->pluck('id'))
            ->selectRaw('SUM((principal_due - paid_principal) + (profit_due - paid_profit)) as bal')->value('bal') ?? '0';

        return [$this->money($sav), $this->money($dps), $this->money($fdr), $this->money($member->share_amount), $this->money($outstanding)];
    }

    private function memberBalance(array $filters): array
    {
        $rows = [];
        $t = [BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero()];
        foreach ($this->membersQuery($filters)->with('samity:id,name')->orderBy('member_no')->limit(1000)->get() as $member) {
            $b = $this->memberBalances($member);
            foreach ($b as $i => $v) {
                $t[$i] = $t[$i]->plus($v);
            }
            $rows[] = [$member->member_no, $member->name, $member->samity?->name ?? '—', ...$b];
        }

        return [
            'columns' => ['member_no', 'member_name', 'samity', 'savings_balance', 'dps_balance', 'fdr_balance', 'share_balance', 'loan_outstanding'],
            'rows' => $rows,
            'total_row' => ['', __('total'), '', ...array_map(fn ($v) => $this->money($v), $t)],
        ];
    }

    private function samityBalance(array $filters): array
    {
        $rows = [];
        foreach (Samity::whereIn('branch_id', $this->branchIds($filters))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('field_officer_id', (int) $filters['employee_id']))
            ->orderBy('name')->get() as $samity) {
            $t = [BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero(), BigDecimal::zero()];
            $members = Member::where('samity_id', $samity->id)->get();
            foreach ($members as $member) {
                foreach ($this->memberBalances($member) as $i => $v) {
                    $t[$i] = $t[$i]->plus($v);
                }
            }
            $rows[] = [$samity->samity_code, $samity->name, (string) $members->count(),
                $this->money($t[0]), $this->money($t[1]), $this->money($t[2]), $this->money($t[3]),
                $this->money($t[0]->plus($t[1])->plus($t[2])->plus($t[3])), $this->money($t[4])];
        }

        return [
            'columns' => ['samity_code', 'samity', 'members', 'savings_balance', 'dps_balance', 'fdr_balance', 'share_balance', 'deposit_balance', 'loan_outstanding'],
            'rows' => $rows,
            'total_row' => null,
        ];
    }

    private function officerBalance(array $filters): array
    {
        $rows = [];
        foreach (Employee::orderBy('name')->get() as $employee) {
            $samityIds = Samity::where('field_officer_id', $employee->id)
                ->whereIn('branch_id', $this->branchIds($filters))->pluck('id');
            if ($samityIds->isEmpty()) {
                continue;
            }
            $members = Member::whereIn('samity_id', $samityIds)->get();
            $deposit = BigDecimal::zero();
            $outstanding = BigDecimal::zero();
            foreach ($members as $member) {
                $b = $this->memberBalances($member);
                $deposit = $deposit->plus($b[0])->plus($b[1])->plus($b[2])->plus($b[3]);
                $outstanding = $outstanding->plus($b[4]);
            }
            $rows[] = [$employee->employee_code, $employee->name, (string) $samityIds->count(),
                (string) $members->count(), $this->money($deposit), $this->money($outstanding)];
        }

        return [
            'columns' => ['employee_code', 'employee', 'samities', 'members', 'deposit_balance', 'loan_outstanding'],
            'rows' => $rows,
            'total_row' => null,
        ];
    }
}
