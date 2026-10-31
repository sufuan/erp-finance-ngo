<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DepositAccount;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\Member;
use App\Models\OrganizationProfile;
use App\Services\AccountReportService;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 9 / Step 30 — Account Report, Ledger & Reports, and
 * Statements & Analysis. Tabular reports share one registry + page
 * (the deposit/loan report pattern: filter card → run → table → PDF);
 * structured statements (balance sheet, income & expenses, P&L) share
 * the Statements page. Branch scope: active branch by default,
 * ?branch_id= for one accessible branch, ?branch_id=all for the
 * consolidated view across every branch the user may access — a
 * foreign branch id is a 403, enforced here AND in the service calls
 * (only accessible ids are ever passed down).
 */
class AccountReportController extends Controller
{
    public function __construct(
        private AccountReportService $reports,
        private BranchAccessService $branchAccess,
        private BusinessDayService $businessDays,
    ) {}

    /** @return array{0: array<int,int>, 1: string} branch ids + scope label */
    private function scope(Request $request): array
    {
        $accessible = $this->branchAccess->accessibleIds($request->user());
        $param = $request->query('branch_id');
        if ($param === 'all') {
            return [$accessible, 'all'];
        }
        if ($param !== null && $param !== '') {
            abort_unless($this->branchAccess->canAccess($request->user(), (int) $param), 403);

            return [[(int) $param], (string) $param];
        }
        $active = $request->attributes->get('active_branch');
        $id = $active?->id ?? $accessible[0] ?? 0;

        return [[$id], (string) $id];
    }

    private function branchId(Request $request): int
    {
        [$ids] = $this->scope($request);

        return $ids[0] ?? 0;
    }

    private function dates(Request $request): array
    {
        $branchId = $this->branchId($request);
        $businessDate = $this->businessDays->currentDate($branchId);
        $from = (string) $request->query('from', $this->reports->fiscalYearStart($branchId, $businessDate));
        $to = (string) $request->query('to', $businessDate);

        return [$from, $to, $businessDate];
    }

    /** @return array<string, array{title:string, filters:string[]}> */
    private function registry(): array
    {
        return [
            'account_balance' => ['title' => 'acc_account_balance', 'filters' => ['branch', 'to']],
            'account_receipt_payment' => ['title' => 'acc_account_receipt_payment', 'filters' => ['branch', 'from', 'to']],
            'account_statement' => ['title' => 'acc_account_statement', 'filters' => ['from', 'to', 'account']],
            'customer_statement' => ['title' => 'acc_customer_statement', 'filters' => ['from', 'to', 'member']],
            'cash_book' => ['title' => 'acc_cash_book', 'filters' => ['branch', 'from', 'to']],
            'daily_cash_book' => ['title' => 'acc_daily_cash_book', 'filters' => ['branch', 'date']],
            'ledger_statement' => ['title' => 'acc_ledger_statement', 'filters' => ['branch', 'from', 'to', 'ledger']],
            'receipt_payment' => ['title' => 'acc_receipt_payment', 'filters' => ['branch', 'from', 'to']],
            'receipt_payment_2' => ['title' => 'acc_receipt_payment_2', 'filters' => ['branch', 'from', 'to']],
            'income_expense_topsheet' => ['title' => 'acc_income_expense_topsheet', 'filters' => ['branch', 'from', 'to']],
            'trial_balance' => ['title' => 'acc_trial_balance', 'filters' => ['branch', 'to']],
            'trial_balance_2' => ['title' => 'acc_trial_balance_2', 'filters' => ['branch', 'from', 'to']],
        ];
    }

    public function index(Request $request): Response
    {
        $key = (string) $request->query('report', 'trial_balance');
        $registry = $this->registry();
        abort_unless(isset($registry[$key]), 404);
        [$branchIds, $scopeLabel] = $this->scope($request);
        [$from, $to] = $this->dates($request);
        $ran = $request->boolean('run');
        $payload = $ran ? $this->build($key, $request, $branchIds, $from, $to) : ['columns' => [], 'rows' => [], 'totalRow' => null, 'sections' => [], 'summary' => []];

        return Inertia::render('Accounts/Reports', [
            'reportKey' => $key,
            'reports' => collect($registry)->map(fn ($def, $k) => ['key' => $k, 'title' => $def['title'], 'filters' => $def['filters']])->values()->all(),
            'activeFilters' => $registry[$key]['filters'],
            'filters' => ['branch_id' => $scopeLabel, 'from' => $from, 'to' => $to,
                'date' => (string) $request->query('date', $to), 'ledger_id' => $request->integer('ledger_id') ?: null,
                'account_type' => (string) $request->query('account_type', ''), 'account_id' => $request->integer('account_id') ?: null,
                'member' => (string) $request->query('member', '')],
            'columns' => $payload['columns'], 'rows' => $payload['rows'], 'totalRow' => $payload['totalRow'],
            'sections' => $payload['sections'], 'summary' => $payload['summary'], 'ran' => $ran,
            'branches' => $this->branchAccess->visibleBranches($request->user())
                ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all(),
            'ledgers' => Ledger::where('is_active', true)->orderBy('ledger_code')->get(['id', 'ledger_code', 'name_en'])
                ->map(fn (Ledger $l) => ['id' => $l->id, 'label' => $l->ledger_code.' — '.$l->name_en])->all(),
            'depositAccounts' => DepositAccount::whereIn('branch_id', $branchIds)->orderByDesc('id')->limit(200)
                ->get(['id', 'account_number'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->account_number])->all(),
            'loanAccounts' => LoanAccount::whereIn('branch_id', $branchIds)->orderByDesc('id')->limit(200)
                ->get(['id', 'loan_number'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->loan_number])->all(),
        ]);
    }

    private function build(string $key, Request $request, array $branchIds, string $from, string $to): array
    {
        $empty = ['columns' => [], 'rows' => [], 'totalRow' => null, 'sections' => [], 'summary' => []];

        switch ($key) {
            case 'account_balance':
                $rows = $this->reports->ledgerBalances($branchIds, null, $to);
                return array_merge($empty, [
                    'columns' => ['ledger_code', 'ledger_name', 'ledger_group', 'balance'],
                    'rows' => collect($rows)->map(fn ($r) => [$r['code'], $r['name'], $r['group'], $r['balance']])->all(),
                ]);
            case 'account_receipt_payment':
                $rows = $this->reports->accountReceiptPayment($branchIds, $from, $to);
                return array_merge($empty, [
                    'columns' => ['ledger_code', 'ledger_name', 'ledger_group', 'total_debit', 'total_credit', 'balance'],
                    'rows' => collect($rows)->map(fn ($r) => [$r['code'], $r['name'], $r['group'], $r['debit'], $r['credit'], $r['balance']])->all(),
                ]);
            case 'trial_balance':
                $tb = $this->reports->trialBalance($branchIds, null, $to);
                return array_merge($empty, [
                    'columns' => ['ledger_code', 'ledger_name', 'ledger_group', 'debit', 'credit'],
                    'rows' => collect($tb['rows'])->map(fn ($r) => [$r['code'], $r['name'], $r['group'], $r['tb_debit'], $r['tb_credit']])->all(),
                    'totalRow' => ['', __('total'), '', $tb['total_debit'], $tb['total_credit']],
                    'summary' => ['balanced' => $tb['balanced']],
                ]);
            case 'trial_balance_2':
                $tb = $this->reports->trialBalance($branchIds, $from, $to);
                return array_merge($empty, [
                    'columns' => ['ledger_code', 'ledger_name', 'opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'],
                    'rows' => collect($tb['rows'])->map(fn ($r) => [$r['code'], $r['name'], $r['opening_debit'], $r['opening_credit'], $r['debit'], $r['credit'], $r['closing_debit'], $r['closing_credit']])->all(),
                    'totalRow' => ['', __('total'), $tb['totals']['opening_debit'], $tb['totals']['opening_credit'], $tb['totals']['debit'], $tb['totals']['credit'], $tb['totals']['closing_debit'], $tb['totals']['closing_credit']],
                    'summary' => ['balanced' => $tb['totals']['balanced']],
                ]);
            case 'ledger_statement':
                $ledger = Ledger::find($request->integer('ledger_id'));
                if (! $ledger) {
                    return $empty;
                }
                $st = $this->reports->ledgerStatement($branchIds, $ledger, $from, $to);
                return array_merge($empty, [
                    'columns' => ['date', 'voucher_no', 'particulars', 'debit', 'credit', 'balance'],
                    'rows' => collect($st['rows'])->map(fn ($r) => [$r['date'], $r['voucher_no'], $r['particulars'] ?? '', $r['debit'], $r['credit'], $r['balance']])->all(),
                    'totalRow' => ['', '', __('total'), $st['total_debit'], $st['total_credit'], $st['closing']],
                    'summary' => ['opening' => $st['opening'], 'closing' => $st['closing'], 'ledger' => $st['ledger']['code'].' — '.$st['ledger']['name']],
                ]);
            case 'cash_book':
                $st = $this->reports->cashBook($branchIds, $from, $to);
                return array_merge($empty, [
                    'columns' => ['date', 'voucher_no', 'particulars', 'debit', 'credit', 'balance'],
                    'rows' => collect($st['rows'])->map(fn ($r) => [$r['date'], $r['voucher_no'], $r['particulars'] ?? '', $r['debit'], $r['credit'], $r['balance']])->all(),
                    'totalRow' => ['', '', __('total'), $st['total_debit'], $st['total_credit'], $st['closing']],
                    'summary' => ['opening' => $st['opening'], 'closing' => $st['closing']],
                ]);
            case 'daily_cash_book':
                $date = (string) $request->query('date', $to);
                $day = $this->reports->dailyCashBook($branchIds[0], $date);
                return array_merge($empty, [
                    'columns' => ['cashier', 'cash_in', 'cash_out', 'balance'],
                    'rows' => collect($day['cashiers'])->map(fn ($r) => [$r['name'], $r['cash_in'], $r['cash_out'], $r['balance']])->all(),
                    'summary' => ['opening' => $day['opening'], 'receipts' => $day['receipts'], 'payments' => $day['payments'], 'closing' => $day['closing'], 'date' => $date],
                ]);
            case 'account_statement':
                $type = (string) $request->query('account_type');
                $accountId = $request->integer('account_id');
                if (! in_array($type, ['deposit', 'loan', 'share'], true) || ! $accountId) {
                    return $empty;
                }
                $st = $this->reports->accountStatement($type, $accountId, $from, $to);
                return array_merge($empty, [
                    'columns' => ['date', 'tran_code', 'amount', 'balance'],
                    'rows' => collect($st['rows'])->map(fn ($r) => [$r['date'], $r['tran_code'], $r['amount'], $r['balance_after']])->all(),
                    'summary' => ['opening' => $st['opening'], 'closing' => $st['closing']],
                ]);
            case 'customer_statement':
                $needle = trim((string) $request->query('member', ''));
                if ($needle === '') {
                    return $empty;
                }
                $member = Member::where('member_no', $needle)
                    ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1)->first();
                if (! $member) {
                    return array_merge($empty, ['summary' => ['not_found' => true]]);
                }
                abort_unless($this->branchAccess->canAccess($request->user(), $member->branch_id), 403);
                $cs = $this->reports->customerStatement($member->id, $from, $to);
                return array_merge($empty, [
                    'columns' => ['date', 'account', 'tran_code', 'amount', 'balance'],
                    'rows' => collect($cs['rows'])->map(fn ($r) => [$r['date'], $r['account'], $r['tran_code'], $r['amount'], $r['balance_after']])->all(),
                    'summary' => ['member' => $cs['member']['member_no'].' — '.$cs['member']['name'],
                        'samity' => $cs['member']['samity'] ?? '', 'share_amount' => $cs['share_amount'],
                        'deposits' => collect($cs['deposits'])->map(fn ($d) => $d['account_number'].': '.$d['balance'])->implode(' · ')],
                ]);
            case 'receipt_payment':
            case 'receipt_payment_2':
                $rp = $this->reports->receiptPayment($branchIds, $from, $to, $key === 'receipt_payment_2');
                $sections = [
                    ['heading' => 'receipts', 'rows' => collect($rp['receipts'])->map(fn ($r) => [$r['code'].' — '.$r['name'], $r['amount']])->all(),
                        'total' => ['total_receipts', $rp['total_receipts']]],
                    ['heading' => 'payments', 'rows' => collect($rp['payments'])->map(fn ($r) => [$r['code'].' — '.$r['name'], $r['amount']])->all(),
                        'total' => ['total_payments', $rp['total_payments']]],
                ];
                if ($key === 'receipt_payment_2') {
                    $sections = [
                        ['heading' => 'receipts', 'groups' => $rp['receipt_groups'], 'total' => ['total_receipts', $rp['total_receipts']]],
                        ['heading' => 'payments', 'groups' => $rp['payment_groups'], 'total' => ['total_payments', $rp['total_payments']]],
                    ];
                }
                return array_merge($empty, ['sections' => $sections,
                    'summary' => ['opening' => $rp['opening'], 'closing' => $rp['closing'],
                        'total_receipts' => $rp['total_receipts'], 'total_payments' => $rp['total_payments']]]);
            case 'income_expense_topsheet':
                $ie = $this->reports->incomeExpense($branchIds, $from, $to);
                $top = fn ($rows) => collect($rows)->sortByDesc(fn ($r) => abs((float) $r['balance']))->take(5)
                    ->map(fn ($r) => [$r['code'].' — '.$r['name'], $r['balance']])->all();
                return array_merge($empty, ['sections' => [
                    ['heading' => 'top_income_heads', 'rows' => $top($ie['income']), 'total' => ['total_income', $ie['total_income']]],
                    ['heading' => 'top_expense_heads', 'rows' => $top($ie['expense']), 'total' => ['total_expense', $ie['total_expense']]],
                ], 'summary' => ['surplus' => $ie['surplus']]]);
        }

        return $empty;
    }

    // -- Structured statements --------------------------------------------------

    public function statement(Request $request): Response
    {
        $key = (string) $request->query('statement', 'balance_sheet');
        abort_unless(in_array($key, ['balance_sheet', 'balance_sheet_2', 'income_expenses', 'income_expenses_2', 'profit_loss'], true), 404);
        [$branchIds] = $this->scope($request);
        [$from, $to] = $this->dates($request);
        $ran = $request->boolean('run');
        $data = ['sections' => [], 'summary' => [], 'columnsTable' => null];
        if ($ran) {
            $data = $this->buildStatement($key, $branchIds, $from, $to);
        }

        return Inertia::render('Accounts/Statements', [
            'statementKey' => $key,
            'statements' => [
                ['key' => 'balance_sheet', 'title' => 'acc_balance_sheet'],
                ['key' => 'balance_sheet_2', 'title' => 'acc_balance_sheet_2'],
                ['key' => 'income_expenses', 'title' => 'acc_income_expenses'],
                ['key' => 'income_expenses_2', 'title' => 'acc_income_expenses_2'],
                ['key' => 'profit_loss', 'title' => 'acc_profit_loss'],
            ],
            'filters' => ['branch_id' => (string) $request->query('branch_id', ''), 'from' => $from, 'to' => $to],
            'ran' => $ran, ...$data,
            'branches' => $this->branchAccess->visibleBranches($request->user())
                ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all(),
        ]);
    }

    private function buildStatement(string $key, array $branchIds, string $from, string $to): array
    {
        $sectionRows = fn ($rows) => collect($rows)->map(fn ($r) => [$r['code'].' — '.$r['name'], $r['balance']])->all();

        switch ($key) {
            case 'balance_sheet':
                $bs = $this->reports->balanceSheet($branchIds, $to);
                return ['sections' => [
                    ['heading' => 'assets', 'rows' => $sectionRows($bs['sections']['Asset']), 'total' => ['total_assets', $bs['total_assets']]],
                    ['heading' => 'liabilities', 'rows' => $sectionRows($bs['sections']['Liability']), 'total' => ['total_liabilities', $bs['totals']['Liability']]],
                    ['heading' => 'equity_fund', 'rows' => $sectionRows($bs['sections']['Equity']), 'total' => ['total_equity', $bs['totals']['Equity']]],
                ], 'summary' => ['surplus' => $bs['surplus'], 'total_liab_equity' => $bs['total_liab_equity'],
                    'total_assets' => $bs['total_assets'], 'balanced' => $bs['balanced']], 'columnsTable' => null];
            case 'income_expenses':
                $ie = $this->reports->incomeExpense($branchIds, $from, $to);
                return ['sections' => [
                    ['heading' => 'income', 'rows' => $sectionRows($ie['income']), 'total' => ['total_income', $ie['total_income']]],
                    ['heading' => 'expenses', 'rows' => $sectionRows($ie['expense']), 'total' => ['total_expense', $ie['total_expense']]],
                ], 'summary' => ['surplus' => $ie['surplus']], 'columnsTable' => null];
            case 'profit_loss':
                $ie = $this->reports->incomeExpense($branchIds, $from, $to);
                $openingEquity = '0.00';
                foreach ($this->reports->ledgerBalances($branchIds, null, date('Y-m-d', strtotime($from.' -1 day'))) as $row) {
                    if ($row['group'] === 'Equity') {
                        $openingEquity = (string) \Brick\Math\BigDecimal::of($openingEquity)->plus($row['balance'])->toScale(2);
                    }
                }
                $closingFund = (string) \Brick\Math\BigDecimal::of($openingEquity)->plus($ie['surplus'])->toScale(2);
                return ['sections' => [
                    ['heading' => 'income', 'rows' => $sectionRows($ie['income']), 'total' => ['total_income', $ie['total_income']]],
                    ['heading' => 'expenses', 'rows' => $sectionRows($ie['expense']), 'total' => ['total_expense', $ie['total_expense']]],
                ], 'summary' => ['surplus' => $ie['surplus'], 'opening_fund' => $openingEquity, 'closing_fund' => $closingFund],
                    'columnsTable' => null];
            case 'balance_sheet_2':
            case 'income_expenses_2':
                $kind = $key === 'balance_sheet_2' ? 'balance_sheet' : 'income_expense';
                $byBranch = $this->reports->byBranch($kind, $branchIds, $from, $to);
                $branchNames = Branch::whereIn('id', $branchIds)->get()->keyBy('id');
                $header = ['particulars'];
                foreach ($branchIds as $bid) {
                    $header[] = $branchNames[$bid]->code ?? (string) $bid;
                }
                $header[] = 'total';
                // Compose a row matrix over the union of ledgers.
                $collect = function ($data, $pick) {
                    $out = [];
                    foreach ($data['columns'] as $bid => $col) {
                        foreach ($pick($col) as $label => $amount) {
                            $out[$label][$bid] = $amount;
                        }
                    }
                    foreach ($pick($data['total']) as $label => $amount) {
                        $out[$label]['total'] = $amount;
                    }

                    return $out;
                };
                if ($kind === 'balance_sheet') {
                    $pick = fn ($col) => collect($col['sections'])->flatMap(fn ($rows) => collect($rows)->mapWithKeys(fn ($r) => [$r['code'].' — '.$r['name'] => $r['balance']]))->all()
                        + ['surplus' => $col['surplus']];
                } else {
                    $pick = fn ($col) => collect([...$col['income'], ...$col['expense']])->mapWithKeys(fn ($r) => [$r['code'].' — '.$r['name'] => $r['balance']])->all()
                        + ['surplus' => $col['surplus']];
                }
                $matrix = $collect($byBranch, $pick);
                $rows = [];
                foreach ($matrix as $label => $perBranch) {
                    $row = [$label];
                    foreach ($branchIds as $bid) {
                        $row[] = $perBranch[$bid] ?? '0.00';
                    }
                    $row[] = $perBranch['total'] ?? '0.00';
                    $rows[] = $row;
                }

                return ['sections' => [], 'summary' => [], 'columnsTable' => ['columns' => $header, 'rows' => $rows]];
        }

        return ['sections' => [], 'summary' => [], 'columnsTable' => null];
    }

    // -- PDF ----------------------------------------------------------------------

    public function pdf(Request $request, PdfService $pdf): HttpResponse
    {
        $key = (string) $request->query('report', '');
        $isStatement = str_starts_with($key, 'statement:');
        $org = OrganizationProfile::where('is_active', true)->first() ?? OrganizationProfile::first();
        [$branchIds] = $this->scope($request);
        [$from, $to] = $this->dates($request);

        if ($isStatement) {
            $statementKey = substr($key, strlen('statement:'));
            abort_unless(in_array($statementKey, ['balance_sheet', 'balance_sheet_2', 'income_expenses', 'income_expenses_2', 'profit_loss'], true), 404);
            $data = $this->buildStatement($statementKey, $branchIds, $from, $to);
            $headingKeys = ['assets' => 'acc_assets', 'liabilities' => 'acc_liabilities', 'equity_fund' => 'acc_equity_fund',
                'income' => 'acc_income', 'expenses' => 'acc_expenses', 'receipts' => 'acc_receipts', 'payments' => 'acc_payments'];
            $sections = collect($data['sections'])->map(fn ($s) => [
                'heading' => isset($headingKeys[$s['heading']]) ? __($headingKeys[$s['heading']]) : $s['heading'],
                'rows' => $s['rows'], 'total' => isset($s['total']) ? [__($s['total'][0]), $s['total'][1]] : null,
            ])->all();
            if ($data['columnsTable']) {
                $sections[] = ['heading' => '', 'table' => [
                    'columns' => collect($data['columnsTable']['columns'])->map(fn ($c) => __($c) === $c ? $c : __($c))->all(),
                    'rows' => $data['columnsTable']['rows'],
                ]];
            }
            $html = view('reports.accounts-statement', [
                'org' => $org, 'title' => __('acc_'.$statementKey), 'filter_summary' => $from.' → '.$to,
                'sections' => $sections,
                'summary' => collect($data['summary'])->map(fn ($v, $k) => [__('acc_'.$k) === 'acc_'.$k ? $k : __('acc_'.$k), $v])->all(),
            ])->render();
            $bytes = $pdf->renderHtmlWithFallback($html, 10);

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$statementKey.'.pdf"']);
        }

        $registry = $this->registry();
        abort_unless(isset($registry[$key]), 404);
        $payload = $this->build($key, $request, $branchIds, $from, $to);
        $html = view('reports.generic', [
            'org' => $org, 'title' => __($registry[$key]['title']),
            'filter_summary' => $from.' → '.$to,
            'columns' => collect($payload['columns'])->map(fn ($c) => __($c))->all(),
            'rows' => $payload['rows'], 'total_row' => $payload['totalRow'],
        ])->render();
        $bytes = $pdf->renderHtmlWithFallback($html, count($payload['rows']));

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$key.'.pdf"']);
    }
}
