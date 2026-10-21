<?php

namespace App\Services;

use App\Models\LoanAccount;
use App\Models\LoanReceive;
use App\Models\LoanWaiver;
use App\Models\Writeoff;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step 20 — loan report registry on the Step 14 pattern: {key, title,
 * filters, pdf_view}; rows are string arrays aligned with `columns`
 * (lang keys), rendered by the shared generic web table + PDF view.
 */
class LoanReportService
{
    public function __construct(private LoanMoneyService $money) {}

    /** @return array<string, array{title: string, filters: string[], pdf_view: string}> */
    public function registry(): array
    {
        $base = ['samity_id', 'employee_id', 'member', 'from_date', 'to_date'];

        return [
            'loan_recovery_details' => ['title' => 'loan_recovery_details', 'filters' => [...$base, 'recovery_type'], 'pdf_view' => 'reports.generic'],
            'loan_collection_register' => ['title' => 'loan_collection_register', 'filters' => [...$base, 'account_type', 'month_wise'], 'pdf_view' => 'reports.generic'],
            'member_loan_balance' => ['title' => 'member_loan_balance', 'filters' => ['samity_id', 'employee_id', 'member', 'loan_type', 'profit_rate'], 'pdf_view' => 'reports.generic'],
            'samity_loan_outstanding' => ['title' => 'samity_loan_outstanding', 'filters' => ['samity_id', 'employee_id'], 'pdf_view' => 'reports.generic'],
            'officer_loan_outstanding' => ['title' => 'officer_loan_outstanding', 'filters' => ['employee_id', 'loan_type'], 'pdf_view' => 'reports.generic'],
            'rent_report' => ['title' => 'rent_report', 'filters' => ['from_date', 'to_date', 'samity_id'], 'pdf_view' => 'reports.generic'],
            'loan_disbursement_register' => ['title' => 'loan_disbursement_register', 'filters' => [...$base, 'account_type', 'frequency', 'disbursement_day', 'opening_type'], 'pdf_view' => 'reports.generic'],
            'disbursement_collection_register' => ['title' => 'disbursement_collection_register', 'filters' => [...$base, 'account_type', 'loan_status'], 'pdf_view' => 'reports.generic'],
            'fully_paid_register' => ['title' => 'fully_paid_register', 'filters' => $base, 'pdf_view' => 'reports.generic'],
            'loan_waiver_report' => ['title' => 'loan_waiver_report', 'filters' => $base, 'pdf_view' => 'reports.generic'],
            'written_off_register' => ['title' => 'written_off_register', 'filters' => $base, 'pdf_view' => 'reports.generic'],
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->registry()[$key]);
    }

    private function m2(BigDecimal|string $v): string
    {
        return (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HalfUp);
    }

    private function loanQuery(array $f): Builder
    {
        return LoanAccount::query()
            ->when(! empty($f['samity_id']), fn ($q) => $q->whereHas('member', fn ($m) => $m->where('samity_id', $f['samity_id'])))
            ->when(! empty($f['employee_id']), fn ($q) => $q->whereHas('member.samity', fn ($s) => $s->where('field_officer_id', $f['employee_id'])))
            ->when(! empty($f['member']), fn ($q) => $q->whereHas('member', fn ($m) => $m->where('member_no', 'like', '%'.$f['member'].'%')))
            ->when(! empty($f['account_type']), fn ($q) => $q->where('loan_product_id', $f['account_type']))
            ->when(! empty($f['frequency']), fn ($q) => $q->where('frequency', $f['frequency']))
            ->with(['member.samity', 'product']);
    }

    private function receiveQuery(array $f): Builder
    {
        return LoanReceive::query()->whereNull('cancel_at')
            ->when(! empty($f['from_date']), fn ($q) => $q->whereDate('receive_date', '>=', $f['from_date']))
            ->when(! empty($f['to_date']), fn ($q) => $q->whereDate('receive_date', '<=', $f['to_date']))
            ->whereHas('loan', function ($q) use ($f) {
                $q->when(! empty($f['samity_id']), fn ($x) => $x->whereHas('member', fn ($m) => $m->where('samity_id', $f['samity_id'])))
                    ->when(! empty($f['employee_id']), fn ($x) => $x->whereHas('member.samity', fn ($s) => $s->where('field_officer_id', $f['employee_id'])))
                    ->when(! empty($f['member']), fn ($x) => $x->whereHas('member', fn ($m) => $m->where('member_no', 'like', '%'.$f['member'].'%')))
                    ->when(! empty($f['account_type']), fn ($x) => $x->where('loan_product_id', $f['account_type']));
            })
            ->with(['loan.member.samity', 'loan.product']);
    }

    /** @return array{columns: string[], rows: array<int, array<int, string>>, total_row: ?array} */
    public function run(string $key, array $filters): array
    {
        return match ($key) {
            'loan_recovery_details' => $this->recoveryDetails($filters),
            'loan_collection_register' => $this->collectionRegister($filters),
            'member_loan_balance' => $this->memberLoanBalance($filters),
            'samity_loan_outstanding' => $this->groupedOutstanding($filters, 'samity'),
            'officer_loan_outstanding' => $this->groupedOutstanding($filters, 'officer'),
            'rent_report' => $this->rentReport($filters),
            'loan_disbursement_register' => $this->disbursementRegister($filters),
            'disbursement_collection_register' => $this->disbursementCollection($filters),
            'fully_paid_register' => $this->fullyPaid($filters),
            'loan_waiver_report' => $this->waiverReport($filters),
            'written_off_register' => $this->writtenOff($filters),
            default => ['columns' => [], 'rows' => [], 'total_row' => null],
        };
    }

    private function recoveryDetails(array $f): array
    {
        $receives = $this->receiveQuery($f)
            ->when(($f['recovery_type'] ?? 'all') === 'cash', fn ($q) => $q->where('transfer_from_deposit', false))
            ->when(($f['recovery_type'] ?? 'all') === 'deposit_transfer', fn ($q) => $q->where('transfer_from_deposit', true))
            ->orderBy('receive_date')->orderBy('id')->get();
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($receives as $r) {
            $amount = BigDecimal::of((string) $r->investment_amount)->plus((string) $r->profit_amount)->plus((string) $r->penalty_charge);
            $total = $total->plus($amount);
            $rows[] = [
                $r->receive_date?->toDateString(), $r->loan?->loan_number,
                $r->loan?->member?->name, $r->loan?->member?->samity?->name,
                $r->transfer_from_deposit ? 'deposit_transfer' : 'cash',
                (string) $r->investment_amount, (string) $r->profit_amount, (string) $r->penalty_charge,
                $this->m2($amount),
            ];
        }

        return ['columns' => ['date', 'loan_number', 'member_name', 'samity', 'recovery_type', 'principal', 'profit', 'charge', 'total'], 'rows' => $rows, 'total_row' => ['', '', '', '', '', '', '', '', $this->m2($total)]];
    }

    private function collectionRegister(array $f): array
    {
        $receives = $this->receiveQuery($f)->orderBy('receive_date')->orderBy('id')->get();
        if (! empty($f['month_wise'])) {
            $grouped = $receives->groupBy(fn ($r) => $r->receive_date?->format('Y-m'));
            $rows = [];
            $total = BigDecimal::zero();
            foreach ($grouped as $month => $items) {
                $sum = $items->reduce(fn ($s, $r) => $s->plus((string) $r->investment_amount)->plus((string) $r->profit_amount)->plus((string) $r->penalty_charge), BigDecimal::zero());
                $total = $total->plus($sum);
                $rows[] = [$month, (string) $items->count(), $this->m2($sum)];
            }

            return ['columns' => ['month', 'receipts', 'total'], 'rows' => $rows, 'total_row' => ['', '', $this->m2($total)]];
        }
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($receives as $r) {
            $amount = BigDecimal::of((string) $r->investment_amount)->plus((string) $r->profit_amount)->plus((string) $r->penalty_charge);
            $total = $total->plus($amount);
            $rows[] = [
                $r->receive_date?->toDateString(), $r->loan?->product?->code,
                $r->loan?->loan_number, $r->loan?->member?->name,
                $r->loan?->member?->samity?->name, $this->m2($amount),
            ];
        }

        return ['columns' => ['date', 'account_type', 'loan_number', 'member_name', 'samity', 'total'], 'rows' => $rows, 'total_row' => ['', '', '', '', '', $this->m2($total)]];
    }

    private function memberLoanBalance(array $f): array
    {
        $type = $f['loan_type'] ?? 'all';
        $loans = $this->loanQuery($f)
            ->when(! empty($f['profit_rate']), fn ($q) => $q->where('profit_rate', '>=', $f['profit_rate']))
            ->orderBy('id')->get();
        $rows = [];
        $totalOut = BigDecimal::zero();
        foreach ($loans as $loan) {
            $out = $this->money->outstanding($loan);
            $hasReceives = LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->exists();
            $include = match ($type) {
                'installment_receivable' => $out['total'] !== '0.00' && in_array($loan->status, ['Disbursed', 'Active'], true),
                'overdue_only' => $loan->schedules()->whereNull('superseded_by')->where('due_date', '<', now()->toDateString())->where('status', '!=', 'Paid')->exists(),
                'receive_only' => $hasReceives,
                'expire' => $loan->installment_expire_date && $loan->installment_expire_date->toDateString() < now()->toDateString() && $loan->status !== 'Closed',
                'closed' => $loan->status === 'Closed',
                'active' => in_array($loan->status, ['Disbursed', 'Active'], true),
                default => true,
            };
            if (! $include) {
                continue;
            }
            $totalOut = $totalOut->plus($out['total']);
            $rows[] = [
                $loan->member?->member_no, $loan->member?->name, $loan->loan_number,
                $loan->product?->code, (string) $loan->profit_rate, $loan->status,
                $out['principal'], $out['profit'], $out['penalty'], $out['total'],
            ];
        }

        return ['columns' => ['member_no', 'member_name', 'loan_number', 'account_type', 'profit_rate', 'status', 'outstanding_principal', 'outstanding_profit', 'outstanding_penalty', 'total'], 'rows' => $rows, 'total_row' => ['', '', '', '', '', '', '', '', '', $this->m2($totalOut)]];
    }

    private function groupedOutstanding(array $f, string $by): array
    {
        $loans = $this->loanQuery($f)->whereIn('status', ['Disbursed', 'Active'])->orderBy('id')->get();
        $groups = [];
        foreach ($loans as $loan) {
            $label = $by === 'samity'
                ? ($loan->member?->samity?->name ?? '—')
                : ('Officer #'.($loan->member?->samity?->field_officer_id ?? '—'));
            $groups[$label] ??= ['count' => 0, 'disbursed' => BigDecimal::zero(), 'principal' => BigDecimal::zero(), 'profit' => BigDecimal::zero()];
            $out = $this->money->outstanding($loan);
            $groups[$label]['count']++;
            $groups[$label]['disbursed'] = $groups[$label]['disbursed']->plus((string) $loan->loan_amount);
            $groups[$label]['principal'] = $groups[$label]['principal']->plus($out['principal']);
            $groups[$label]['profit'] = $groups[$label]['profit']->plus($out['profit']);
        }
        $rows = [];
        $tp = BigDecimal::zero();
        $tf = BigDecimal::zero();
        foreach ($groups as $label => $g) {
            $tp = $tp->plus($g['principal']);
            $tf = $tf->plus($g['profit']);
            $rows[] = [$label, (string) $g['count'], $this->m2($g['disbursed']), $this->m2($g['principal']), $this->m2($g['profit']), $this->m2($g['principal']->plus($g['profit']))];
        }

        return ['columns' => [$by === 'samity' ? 'samity' : 'employee', 'loans', 'loan_amount', 'outstanding_principal', 'outstanding_profit', 'total'], 'rows' => $rows, 'total_row' => ['', '', '', $this->m2($tp), $this->m2($tf), $this->m2($tp->plus($tf))]];
    }

    private function rentReport(array $f): array
    {
        // "Rent Applied": installments (rent) falling due in the period,
        // grouped by product — due vs collected.
        $from = $f['from_date'] ?? '1970-01-01';
        $to = $f['to_date'] ?? '2999-12-31';
        $loans = $this->loanQuery($f)->get();
        $groups = [];
        foreach ($loans as $loan) {
            $dueRows = $loan->schedules()->whereNull('superseded_by')
                ->whereBetween('due_date', [$from, $to])->get();
            if ($dueRows->isEmpty()) {
                continue;
            }
            $code = $loan->product?->code ?? '—';
            $groups[$code] ??= ['count' => 0, 'due' => BigDecimal::zero(), 'collected' => BigDecimal::zero()];
            foreach ($dueRows as $row) {
                $groups[$code]['count']++;
                $groups[$code]['due'] = $groups[$code]['due']->plus((string) $row->total_due);
                $groups[$code]['collected'] = $groups[$code]['collected']
                    ->plus((string) $row->paid_principal)->plus((string) $row->paid_profit);
            }
        }
        $rows = collect($groups)->map(fn ($g, $code) => [
            $code, 'rent_applied', (string) $g['count'], $this->m2($g['due']), $this->m2($g['collected']),
        ])->values()->all();

        return ['columns' => ['account_type', 'report_type', 'installments', 'due_amount', 'collected'], 'rows' => $rows, 'total_row' => null];
    }

    private function disbursementRegister(array $f): array
    {
        $loans = $this->loanQuery($f)
            ->whereNotNull('disbursed_at')
            ->when(! empty($f['from_date']), fn ($q) => $q->whereDate('disbursed_at', '>=', $f['from_date']))
            ->when(! empty($f['to_date']), fn ($q) => $q->whereDate('disbursed_at', '<=', $f['to_date']))
            ->when(! empty($f['disbursement_day']), fn ($q) => $q->whereRaw('DAY(disbursed_at) = ?', [(int) $f['disbursement_day']]))
            ->orderBy('disbursed_at')->get();
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($loans as $loan) {
            $isRescheduled = $loan->reschedules()->exists();
            $type = $f['opening_type'] ?? 'all';
            if ($type === 'new' && $isRescheduled) {
                continue;
            }
            if ($type === 'reschedule' && ! $isRescheduled) {
                continue;
            }
            $total = $total->plus((string) $loan->loan_amount);
            $rows[] = [
                $loan->disbursed_at?->toDateString(), $loan->loan_number, $loan->product?->code,
                $loan->member?->name, $loan->member?->samity?->name, $loan->frequency,
                $isRescheduled ? 'reschedule' : 'new', (string) $loan->loan_amount,
            ];
        }

        return ['columns' => ['date', 'loan_number', 'account_type', 'member_name', 'samity', 'frequency', 'opening_type', 'loan_amount'], 'rows' => $rows, 'total_row' => ['', '', '', '', '', '', '', $this->m2($total)]];
    }

    private function disbursementCollection(array $f): array
    {
        $loans = $this->loanQuery($f)
            ->when(! empty($f['loan_status']), fn ($q) => $q->where('status', $f['loan_status']))
            ->whereNotNull('disbursed_at')->orderBy('id')->get();
        $rows = [];
        foreach ($loans as $loan) {
            $collected = LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')
                ->selectRaw('COALESCE(SUM(investment_amount + profit_amount + penalty_charge),0) as s')->value('s');
            $out = $this->money->outstanding($loan);
            $rows[] = [
                $loan->loan_number, $loan->member?->name, $loan->status,
                (string) $loan->loan_amount, $this->m2((string) $collected), $out['total'],
            ];
        }

        return ['columns' => ['loan_number', 'member_name', 'status', 'loan_amount', 'collected', 'total'], 'rows' => $rows, 'total_row' => null];
    }

    private function fullyPaid(array $f): array
    {
        $loans = $this->loanQuery($f)->where('status', 'Closed')
            ->when(! empty($f['from_date']), fn ($q) => $q->whereDate('updated_at', '>=', $f['from_date']))
            ->when(! empty($f['to_date']), fn ($q) => $q->whereDate('updated_at', '<=', $f['to_date']))
            ->orderBy('id')->get();
        $rows = $loans->map(fn ($l) => [
            $l->loan_number, $l->member?->name, $l->member?->samity?->name,
            $l->product?->code, (string) $l->loan_amount, (string) $l->total_repayment,
            $l->updated_at?->toDateString(),
        ])->all();

        return ['columns' => ['loan_number', 'member_name', 'samity', 'account_type', 'loan_amount', 'total_repayment', 'date'], 'rows' => $rows, 'total_row' => null];
    }

    private function waiverReport(array $f): array
    {
        $waivers = LoanWaiver::query()->whereNull('cancel_at')
            ->when(! empty($f['from_date']), fn ($q) => $q->whereDate('waiver_date', '>=', $f['from_date']))
            ->when(! empty($f['to_date']), fn ($q) => $q->whereDate('waiver_date', '<=', $f['to_date']))
            ->whereHas('loan', function ($q) use ($f) {
                $q->when(! empty($f['samity_id']), fn ($x) => $x->whereHas('member', fn ($m) => $m->where('samity_id', $f['samity_id'])))
                    ->when(! empty($f['member']), fn ($x) => $x->whereHas('member', fn ($m) => $m->where('member_no', 'like', '%'.$f['member'].'%')));
            })
            ->with(['loan.member'])->orderBy('waiver_date')->get();
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($waivers as $w) {
            $total = $total->plus((string) $w->total_waiver);
            $rows[] = [
                $w->waiver_date?->toDateString(), $w->loan?->loan_number, $w->loan?->member?->name,
                (string) $w->principal_waive, (string) $w->profit_waive, (string) $w->charge_waive, (string) $w->total_waiver,
            ];
        }

        return ['columns' => ['waiver_date', 'loan_number', 'member_name', 'principal_waive', 'profit_waive', 'charge_waive', 'total_waiver'], 'rows' => $rows, 'total_row' => ['', '', '', '', '', '', $this->m2($total)]];
    }

    private function writtenOff(array $f): array
    {
        $writeoffs = Writeoff::query()
            ->when(! empty($f['from_date']), fn ($q) => $q->whereDate('writeoff_date', '>=', $f['from_date']))
            ->when(! empty($f['to_date']), fn ($q) => $q->whereDate('writeoff_date', '<=', $f['to_date']))
            ->whereHas('loan', function ($q) use ($f) {
                $q->when(! empty($f['samity_id']), fn ($x) => $x->whereHas('member', fn ($m) => $m->where('samity_id', $f['samity_id'])))
                    ->when(! empty($f['member']), fn ($x) => $x->whereHas('member', fn ($m) => $m->where('member_no', 'like', '%'.$f['member'].'%')));
            })
            ->with(['loan.member'])->orderBy('writeoff_date')->get();
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($writeoffs as $w) {
            $recovered = \App\Models\WriteoffCollection::where('loan_account_id', $w->loan_account_id)->sum('total_recovery');
            $total = $total->plus((string) $w->total_amount);
            $rows[] = [
                $w->writeoff_date?->toDateString(), $w->loan?->loan_number, $w->loan?->member?->name,
                (string) $w->principal, (string) $w->profit, (string) $w->charge,
                (string) $w->total_amount, $this->m2((string) $recovered),
            ];
        }

        return ['columns' => ['writeoff_date', 'loan_number', 'member_name', 'principal', 'profit', 'charge', 'total', 'total_recovery'], 'rows' => $rows, 'total_row' => ['', '', '', '', '', '', $this->m2($total), '']];
    }
}
