<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\DepositTransaction;
use App\Models\ProfitPayment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

/**
 * Step 14 — report registry: {key, title, filters, query, pdf_view}.
 * Every deposit report runs through `run()` and renders through the same
 * generic web table + `reports.generic` PDF view, so later modules plug
 * new reports into the same pattern. Rows are plain string arrays aligned
 * with `columns` (lang keys).
 */
class ReportService
{
    /** Product-code groups behind the three account registers. */
    private const GROUPS = [
        'fdr_register' => ['FDR'],
        'dps_register' => ['DPS', 'LTS'],
        'savings_register' => ['SAVINGS', 'CSAV'],
    ];

    /** @return array<string, array{title: string, filters: string[], pdf_view: string}> */
    public function registry(): array
    {
        $registerFilters = ['samity_id', 'employee_id', 'member', 'from_date', 'to_date', 'account_status'];
        $txnFilters = ['samity_id', 'employee_id', 'member', 'from_date', 'to_date'];

        return [
            'fdr_register' => ['title' => 'fdr_register', 'filters' => $registerFilters, 'pdf_view' => 'reports.generic'],
            'dps_register' => ['title' => 'dps_register', 'filters' => $registerFilters, 'pdf_view' => 'reports.generic'],
            'savings_register' => ['title' => 'savings_register', 'filters' => $registerFilters, 'pdf_view' => 'reports.generic'],
            'collection_register' => ['title' => 'deposit_collection_register', 'filters' => $txnFilters, 'pdf_view' => 'reports.generic'],
            'refund_register' => ['title' => 'deposit_refund_register', 'filters' => $txnFilters, 'pdf_view' => 'reports.generic'],
            'profit_payment_register' => ['title' => 'profit_payment_register', 'filters' => $txnFilters, 'pdf_view' => 'reports.generic'],
            'dps_target_vs_collection' => ['title' => 'dps_target_vs_collection', 'filters' => ['samity_id', 'employee_id', 'member', 'from_date', 'to_date'], 'pdf_view' => 'reports.generic'],
            'monthly_deposit_transaction' => ['title' => 'monthly_deposit_transaction', 'filters' => ['year', 'samity_id'], 'pdf_view' => 'reports.generic'],
            'member_profit_balance' => ['title' => 'member_profit_balance', 'filters' => ['samity_id', 'member', 'profit_type'], 'pdf_view' => 'reports.generic'],
            'zero_transaction_report' => ['title' => 'zero_transaction_report', 'filters' => ['samity_id', 'employee_id', 'member', 'from_date', 'to_date'], 'pdf_view' => 'reports.generic'],
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
            'fdr_register', 'dps_register', 'savings_register' => $this->accountRegister($key, $filters),
            'collection_register' => $this->transactionRegister($filters, 'Receive'),
            'refund_register' => $this->transactionRegister($filters, 'Payment'),
            'profit_payment_register' => $this->profitPaymentRegister($filters),
            'dps_target_vs_collection' => $this->dpsTargetVsCollection($filters),
            'monthly_deposit_transaction' => $this->monthlyTransactions($filters),
            'member_profit_balance' => $this->memberProfitBalance($filters),
            'zero_transaction_report' => $this->zeroTransactions($filters),
            default => ['columns' => [], 'rows' => [], 'total_row' => null],
        };
    }

    // ---- shared query scopes ----

    private function accountQuery(array $filters)
    {
        $query = DepositAccount::with(['member.samity', 'product'])->orderBy('id');
        if (! empty($filters['samity_id'])) {
            $query->whereHas('member', fn ($q) => $q->where('samity_id', $filters['samity_id']));
        }
        if (! empty($filters['employee_id'])) {
            $query->whereHas('member.samity', fn ($q) => $q->where('field_officer_id', $filters['employee_id']));
        }
        if (! empty($filters['member'])) {
            $needle = trim((string) $filters['member']);
            $query->whereHas('member', fn ($q) => $q->where('member_no', $needle)
                ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1));
        }

        return $query;
    }

    private function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }

    // ---- the three account registers ----

    private function accountRegister(string $key, array $filters): array
    {
        $query = $this->accountQuery($filters)
            ->whereHas('product', fn ($q) => $q->whereIn('code', self::GROUPS[$key]));
        $from = $filters['from_date'] ?? null;
        $to = $filters['to_date'] ?? null;
        switch ($filters['account_status'] ?? 'all') {
            case 'open_only':
                $query->where('is_active', true)->whereNull('closing_date');
                break;
            case 'upto_closed_only':
                $query->whereNotNull('closing_date')->when($to, fn ($q) => $q->where('closing_date', '<=', $to));
                break;
            case 'open_from_upto':
                $query->where('is_active', true)->whereNull('closing_date')
                    ->when($from, fn ($q) => $q->where('opening_date', '>=', $from))
                    ->when($to, fn ($q) => $q->where('opening_date', '<=', $to));
                break;
            case 'closed_from_upto':
                $query->whereNotNull('closing_date')
                    ->when($from, fn ($q) => $q->where('closing_date', '>=', $from))
                    ->when($to, fn ($q) => $q->where('closing_date', '<=', $to));
                break;
            case 'maturity_between':
                $query->whereNotNull('maturity_date')
                    ->when($from, fn ($q) => $q->where('maturity_date', '>=', $from))
                    ->when($to, fn ($q) => $q->where('maturity_date', '<=', $to));
                break;
            case 'upto_open_only':
                $query->when($to, fn ($q) => $q->where('opening_date', '<=', $to))
                    ->where(fn ($q) => $q->whereNull('closing_date')->orWhere('closing_date', '>', $to ?? '9999-12-31'));
                break;
            default: // all — still honour an opening-date range when given
                $query->when($from, fn ($q) => $q->where('opening_date', '>=', $from))
                    ->when($to, fn ($q) => $q->where('opening_date', '<=', $to));
        }
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($query->get() as $i => $a) {
            $rows[] = [
                (string) ($i + 1), $a->account_number,
                $a->member ? $a->member->member_no.' — '.$a->member->name : '—',
                $a->member?->samity ? $a->member->samity->samity_code.' — '.$a->member->samity->name : '—',
                $a->product?->code ?? '—',
                $a->opening_date?->toDateString() ?? '—',
                $a->maturity_date?->toDateString() ?? '—',
                $a->installment_amount !== null ? $this->money($a->installment_amount) : '—',
                (string) $a->profit_rate,
                $this->money($a->current_balance),
                $a->closing_date ? 'Closed' : 'Open',
            ];
            $total = $total->plus((string) $a->current_balance);
        }

        return [
            'columns' => ['sl', 'account_number', 'member_no', 'samity', 'product', 'opening_date', 'maturity_date', 'installment_amount', 'profit_rate', 'current_balance', 'status'],
            'rows' => $rows,
            'total_row' => ['total', '', '', '', '', '', '', '', '', $this->money($total), ''],
        ];
    }

    // ---- transaction registers (collection / refund) ----

    private function transactionRegister(array $filters, string $tranType): array
    {
        $query = DepositTransaction::with(['account.member.samity'])
            ->where('tran_type', $tranType)->whereNull('cancel_at')->orderBy('tran_date')->orderBy('id');
        $this->applyTxnFilters($query, $filters);
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($query->get() as $i => $tx) {
            $rows[] = [
                (string) ($i + 1), $tx->tran_date?->toDateString() ?? '—',
                $tx->account?->account_number ?? '—',
                $tx->account?->member ? $tx->account->member->member_no.' — '.$tx->account->member->name : '—',
                $tx->account?->member?->samity ? $tx->account->member->samity->samity_code.' — '.$tx->account->member->samity->name : '—',
                $tx->product ?? '—', $this->money($tx->amount), $tx->document_number ?? '—',
            ];
            $total = $total->plus((string) $tx->amount);
        }

        return [
            'columns' => ['sl', 'date', 'account_number', 'member_no', 'samity', 'product', 'amount', 'document_number'],
            'rows' => $rows,
            'total_row' => ['total', '', '', '', '', '', $this->money($total), ''],
        ];
    }

    private function applyTxnFilters($query, array $filters): void
    {
        if (! empty($filters['from_date'])) {
            $query->where('tran_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->where('tran_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['samity_id'])) {
            $query->whereHas('account.member', fn ($q) => $q->where('samity_id', $filters['samity_id']));
        }
        if (! empty($filters['employee_id'])) {
            $query->whereHas('account.member.samity', fn ($q) => $q->where('field_officer_id', $filters['employee_id']));
        }
        if (! empty($filters['member'])) {
            $needle = trim((string) $filters['member']);
            $query->whereHas('account.member', fn ($q) => $q->where('member_no', $needle)
                ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1));
        }
    }

    private function profitPaymentRegister(array $filters): array
    {
        $query = ProfitPayment::with(['account.member.samity'])->orderBy('payment_date')->orderBy('id');
        if (! empty($filters['from_date'])) {
            $query->where('payment_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->where('payment_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['samity_id'])) {
            $query->whereHas('account.member', fn ($q) => $q->where('samity_id', $filters['samity_id']));
        }
        if (! empty($filters['employee_id'])) {
            $query->whereHas('account.member.samity', fn ($q) => $q->where('field_officer_id', $filters['employee_id']));
        }
        if (! empty($filters['member'])) {
            $needle = trim((string) $filters['member']);
            $query->whereHas('account.member', fn ($q) => $q->where('member_no', $needle)
                ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1));
        }
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($query->get() as $i => $p) {
            $rows[] = [
                (string) ($i + 1), $p->payment_date?->toDateString() ?? '—',
                $p->account?->account_number ?? '—',
                $p->account?->member ? $p->account->member->member_no.' — '.$p->account->member->name : '—',
                $p->account?->member?->samity ? $p->account->member->samity->samity_code.' — '.$p->account->member->samity->name : '—',
                $this->money($p->profit_amount), $p->comments ?? '—',
            ];
            $total = $total->plus((string) $p->profit_amount);
        }

        return [
            'columns' => ['sl', 'date', 'account_number', 'member_no', 'samity', 'profit_amount', 'comments'],
            'rows' => $rows,
            'total_row' => ['total', '', '', '', '', $this->money($total), ''],
        ];
    }

    // ---- DPS target vs collection ----

    private function dpsTargetVsCollection(array $filters): array
    {
        $from = Carbon::parse($filters['from_date'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($filters['to_date'] ?? now()->toDateString());
        // Monthly-equivalent target: whole months spanned by the period.
        $months = max(1, $from->diffInMonths($to) + 1);
        $accounts = $this->accountQuery($filters)
            ->whereHas('product', fn ($q) => $q->whereIn('code', ['DPS', 'LTS']))->get();
        $rows = [];
        foreach ($accounts as $i => $a) {
            $installment = BigDecimal::of((string) ($a->installment_amount ?? '0'));
            $target = $installment->multipliedBy($months);
            $collected = DepositTransaction::where('account_id', $a->id)->where('tran_type', 'Receive')
                ->whereNull('cancel_at')->whereBetween('tran_date', [$from->toDateString(), $to->toDateString()])->sum('amount');
            $collected = BigDecimal::of((string) $collected);
            $rows[] = [
                (string) ($i + 1), $a->account_number,
                $a->member ? $a->member->member_no.' — '.$a->member->name : '—',
                $a->member?->samity ? $a->member->samity->samity_code.' — '.$a->member->samity->name : '—',
                $this->money($installment), $this->money($target), $this->money($collected),
                $this->money($target->minus($collected)),
            ];
        }

        return [
            'columns' => ['sl', 'account_number', 'member_no', 'samity', 'installment_amount', 'target_amount', 'collection_amount', 'shortfall'],
            'rows' => $rows, 'total_row' => null,
        ];
    }

    // ---- monthly deposit transaction (one row per month of a year) ----

    private function monthlyTransactions(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);
        $rows = [];
        $grandReceive = BigDecimal::zero();
        $grandPayment = BigDecimal::zero();
        for ($m = 1; $m <= 12; $m++) {
            $base = DepositTransaction::whereNull('cancel_at')
                ->whereYear('tran_date', $year)->whereMonth('tran_date', $m);
            if (! empty($filters['samity_id'])) {
                $base->whereHas('account.member', fn ($q) => $q->where('samity_id', $filters['samity_id']));
            }
            $receive = BigDecimal::of((string) (clone $base)->where('tran_type', 'Receive')->sum('amount'));
            $payment = BigDecimal::of((string) (clone $base)->where('tran_type', 'Payment')->sum('amount'));
            $rows[] = [
                (string) $m, Carbon::create($year, $m, 1)->format('F'),
                $this->money($receive), $this->money($payment), $this->money($receive->minus($payment)),
            ];
            $grandReceive = $grandReceive->plus($receive);
            $grandPayment = $grandPayment->plus($payment);
        }

        return [
            'columns' => ['sl', 'month', 'receive_amount', 'payment_amount', 'net_amount'],
            'rows' => $rows,
            'total_row' => ['total', '', $this->money($grandReceive), $this->money($grandPayment), $this->money($grandReceive->minus($grandPayment))],
        ];
    }

    // ---- member profit balance ----

    private function memberProfitBalance(array $filters): array
    {
        $query = $this->accountQuery($filters);
        if (! empty($filters['profit_type'])) {
            $query->whereHas('product', fn ($q) => $q->where('code', $filters['profit_type']));
        }
        $rows = [];
        foreach ($query->get() as $i => $a) {
            $distributed = \App\Models\ProfitDistributionLine::where('account_id', $a->id)
                ->whereHas('distribution', fn ($q) => $q->where('status', 'Posted'))->sum('total_payable');
            $pending = \App\Models\ProfitDistributionLine::where('account_id', $a->id)
                ->whereHas('distribution', fn ($q) => $q->where('status', 'Posted'))->sum('profit_pending');
            $paid = ProfitPayment::where('deposit_account_id', $a->id)->sum('profit_amount');
            if (BigDecimal::of((string) $distributed)->isZero() && BigDecimal::of((string) $paid)->isZero()) {
                continue;
            }
            $rows[] = [
                (string) (count($rows) + 1), $a->account_number,
                $a->member ? $a->member->member_no.' — '.$a->member->name : '—',
                $a->product?->code ?? '—',
                $this->money($distributed), $this->money($paid), $this->money($pending),
            ];
        }

        return [
            'columns' => ['sl', 'account_number', 'member_no', 'product', 'distributed_profit', 'profit_paid', 'profit_due'],
            'rows' => $rows, 'total_row' => null,
        ];
    }

    // ---- zero transaction report ----

    private function zeroTransactions(array $filters): array
    {
        $from = $filters['from_date'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to_date'] ?? now()->toDateString();
        $accounts = $this->accountQuery($filters)
            ->whereDoesntHave('transactions', fn ($q) => $q->whereNull('cancel_at')
                ->whereBetween('tran_date', [$from, $to]))
            ->get();
        $rows = [];
        foreach ($accounts as $i => $a) {
            $rows[] = [
                (string) ($i + 1), $a->account_number,
                $a->member ? $a->member->member_no.' — '.$a->member->name : '—',
                $a->member?->samity ? $a->member->samity->samity_code.' — '.$a->member->samity->name : '—',
                $a->product?->code ?? '—', $a->opening_date?->toDateString() ?? '—',
                $this->money($a->current_balance),
            ];
        }

        return [
            'columns' => ['sl', 'account_number', 'member_no', 'samity', 'product', 'opening_date', 'current_balance'],
            'rows' => $rows, 'total_row' => null,
        ];
    }
}
