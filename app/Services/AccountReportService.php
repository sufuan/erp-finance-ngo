<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\DepositAccount;
use App\Models\FiscalYear;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\Member;
use App\Models\VoucherLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * AccountReportService (Module 9, Step 30) — every Accounts statement,
 * computed from the same sources the posting engine writes:
 *
 *  - GL figures come from voucher_lines joined to vouchers, EXCLUDING
 *    Cancelled vouchers only — the exact convention of
 *    PostingService::ledgerBalance()/trialBalance(), so a statement
 *    can never disagree with the engine.
 *  - Sub-ledger statements come from account_transactions rows that
 *    were never cancelled (cancel_at IS NULL).
 *
 * Sign convention: a ledger's balance is reported in its NORMAL
 * direction (Asset/Expense: Dr−Cr; Liability/Equity/Income: Cr−Dr),
 * matching PostingService::ledgerBalance(). Negative values mean the
 * balance sits on the opposite side.
 */
class AccountReportService
{
    private const DEBIT_NORMAL = ['Asset', 'Expense'];

    /** Fiscal-year start for a branch, falling back to July 1. */
    public function fiscalYearStart(int $branchId, string $onDate): string
    {
        $fy = FiscalYear::where('branch_id', $branchId)
            ->where('start_date', '<=', $onDate)->where('end_date', '>=', $onDate)
            ->orderByDesc('start_date')->first();
        if ($fy) {
            return $fy->start_date->toDateString();
        }
        $ts = strtotime($onDate);
        $year = (int) date('Y', $ts);

        return (date('m', $ts) >= 7 ? $year : $year - 1).'-07-01';
    }

    // -- raw sums -----------------------------------------------------------

    /**
     * Per-ledger [debit, credit] sums over non-cancelled vouchers.
     * $before: transaction_date < $from; else $from..$to inclusive.
     *
     * @return array<int, array{debit:string, credit:string}>
     */
    private function sums(array $branchIds, ?string $from, ?string $to, bool $before = false): array
    {
        $query = VoucherLine::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_lines.voucher_id')
            ->whereIn('vouchers.branch_id', $branchIds)
            ->where('vouchers.status', '!=', 'Cancelled');
        if ($before) {
            $query->where('vouchers.transaction_date', '<', $from);
        } else {
            if ($from) {
                $query->where('vouchers.transaction_date', '>=', $from);
            }
            if ($to) {
                $query->where('vouchers.transaction_date', '<=', $to);
            }
        }
        $rows = $query->groupBy('voucher_lines.ledger_id')
            ->selectRaw('voucher_lines.ledger_id AS lid, COALESCE(SUM(voucher_lines.debit),0) AS d, COALESCE(SUM(voucher_lines.credit),0) AS c')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->lid] = ['debit' => (string) $row->d, 'credit' => (string) $row->c];
        }

        return $out;
    }

    private function signed(Ledger $ledger, string $debit, string $credit): string
    {
        $d = BigDecimal::of($debit);
        $c = BigDecimal::of($credit);
        $balance = in_array($ledger->ledger_group, self::DEBIT_NORMAL, true) ? $d->minus($c) : $c->minus($d);

        return (string) $balance->toScale(2);
    }

    private function ledgerRow(Ledger $ledger, string $debit, string $credit): array
    {
        return [
            'ledger_id' => $ledger->id, 'code' => $ledger->ledger_code,
            'name' => $ledger->name_en, 'name_bn' => $ledger->name_bn,
            'group' => $ledger->ledger_group, 'is_control' => $ledger->is_control,
            'debit' => (string) BigDecimal::of($debit)->toScale(2),
            'credit' => (string) BigDecimal::of($credit)->toScale(2),
            'balance' => $this->signed($ledger, $debit, $credit),
        ];
    }

    /** Balances of every ledger with activity, cumulative up to $to (or period when $from given). */
    public function ledgerBalances(array $branchIds, ?string $from, ?string $to): array
    {
        $sums = $this->sums($branchIds, $from, $to);
        $ledgers = Ledger::whereIn('id', array_keys($sums))->orderBy('ledger_code')->get();
        $rows = [];
        foreach ($ledgers as $ledger) {
            $s = $sums[$ledger->id];
            $rows[] = $this->ledgerRow($ledger, $s['debit'], $s['credit']);
        }

        return $rows;
    }

    /** One ledger's signed balance up to a date (or before it). */
    public function balanceUpTo(array $branchIds, Ledger $ledger, string $date, bool $before = false): string
    {
        $sums = $before
            ? $this->sums($branchIds, $date, null, true)
            : $this->sums($branchIds, null, $date);
        $s = $sums[$ledger->id] ?? ['debit' => '0', 'credit' => '0'];

        return $this->signed($ledger, $s['debit'], $s['credit']);
    }

    // -- Trial balance ------------------------------------------------------

    /**
     * Simple TB (no $from): each ledger's balance placed on its normal
     * side (or flipped when negative), totals must tie. Extended TB
     * (with $from): opening | period Dr | period Cr | closing columns.
     */
    public function trialBalance(array $branchIds, ?string $from, ?string $to): array
    {
        if (! $from) {
            $rows = $this->ledgerBalances($branchIds, null, $to);
            $totalDebit = BigDecimal::zero();
            $totalCredit = BigDecimal::zero();
            $out = [];
            foreach ($rows as $row) {
                $balance = BigDecimal::of($row['balance']);
                if ($balance->isZero()) {
                    continue;
                }
                $onDebitSide = in_array($row['group'], self::DEBIT_NORMAL, true) === $balance->isPositive();
                $row['tb_debit'] = $onDebitSide ? (string) $balance->abs()->toScale(2) : '0.00';
                $row['tb_credit'] = $onDebitSide ? '0.00' : (string) $balance->abs()->toScale(2);
                $totalDebit = $totalDebit->plus($row['tb_debit']);
                $totalCredit = $totalCredit->plus($row['tb_credit']);
                $out[] = $row;
            }

            return ['rows' => $out, 'total_debit' => (string) $totalDebit->toScale(2),
                'total_credit' => (string) $totalCredit->toScale(2),
                'balanced' => $totalDebit->isEqualTo($totalCredit)];
        }

        $opening = $this->sums($branchIds, $from, null, true);
        $period = $this->sums($branchIds, $from, $to);
        $ids = array_unique(array_merge(array_keys($opening), array_keys($period)));
        $ledgers = Ledger::whereIn('id', $ids)->orderBy('ledger_code')->get()->keyBy('id');
        $rows = [];
        $tot = ['opening_debit' => BigDecimal::zero(), 'opening_credit' => BigDecimal::zero(),
            'debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(),
            'closing_debit' => BigDecimal::zero(), 'closing_credit' => BigDecimal::zero()];
        foreach ($ids as $id) {
            $ledger = $ledgers[$id];
            $o = $opening[$id] ?? ['debit' => '0', 'credit' => '0'];
            $p = $period[$id] ?? ['debit' => '0', 'credit' => '0'];
            $openingBalance = $this->signed($ledger, $o['debit'], $o['credit']);
            $closingBalance = $this->signed(
                $ledger,
                (string) BigDecimal::of($o['debit'])->plus($p['debit']),
                (string) BigDecimal::of($o['credit'])->plus($p['credit'])
            );
            $place = function (string $signedBalance) use ($ledger) {
                $b = BigDecimal::of($signedBalance);
                if ($b->isZero()) {
                    return ['0.00', '0.00'];
                }
                $onDebit = in_array($ledger->ledger_group, self::DEBIT_NORMAL, true) === $b->isPositive();

                return $onDebit ? [(string) $b->abs()->toScale(2), '0.00'] : ['0.00', (string) $b->abs()->toScale(2)];
            };
            [$od, $oc] = $place($openingBalance);
            [$cd, $cc] = $place($closingBalance);
            $rows[] = $this->ledgerRow($ledger, $p['debit'], $p['credit']) + [
                'opening_debit' => $od, 'opening_credit' => $oc,
                'closing_debit' => $cd, 'closing_credit' => $cc,
                'closing' => $closingBalance,
            ];
            $tot['opening_debit'] = $tot['opening_debit']->plus($od);
            $tot['opening_credit'] = $tot['opening_credit']->plus($oc);
            $tot['debit'] = $tot['debit']->plus($p['debit']);
            $tot['credit'] = $tot['credit']->plus($p['credit']);
            $tot['closing_debit'] = $tot['closing_debit']->plus($cd);
            $tot['closing_credit'] = $tot['closing_credit']->plus($cc);
        }
        $totals = collect($tot)->map(fn ($v) => (string) $v->toScale(2))->all();
        $totals['balanced'] = $tot['closing_debit']->isEqualTo($tot['closing_credit'])
            && $tot['opening_debit']->isEqualTo($tot['opening_credit']);

        return ['rows' => $rows, 'totals' => $totals];
    }

    // -- GL statements ------------------------------------------------------

    /** Ledger statement with running signed balance (normal direction). */
    public function ledgerStatement(array $branchIds, Ledger $ledger, string $from, string $to): array
    {
        $opening = $this->balanceUpTo($branchIds, $ledger, $from, true);
        $lines = VoucherLine::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_lines.voucher_id')
            ->whereIn('vouchers.branch_id', $branchIds)
            ->where('vouchers.status', '!=', 'Cancelled')
            ->where('voucher_lines.ledger_id', $ledger->id)
            ->whereBetween('vouchers.transaction_date', [$from, $to])
            ->orderBy('vouchers.transaction_date')->orderBy('vouchers.id')->orderBy('voucher_lines.id')
            ->select('voucher_lines.debit', 'voucher_lines.credit', 'voucher_lines.comments AS line_comments',
                'vouchers.batch_number', 'vouchers.voucher_type', 'vouchers.transaction_date',
                'vouchers.comments AS voucher_comments', 'vouchers.branch_id')
            ->get();

        $debitNormal = in_array($ledger->ledger_group, self::DEBIT_NORMAL, true);
        $running = BigDecimal::of($opening);
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        $rows = [];
        foreach ($lines as $line) {
            $delta = $debitNormal
                ? BigDecimal::of((string) $line->debit)->minus(BigDecimal::of((string) $line->credit))
                : BigDecimal::of((string) $line->credit)->minus(BigDecimal::of((string) $line->debit));
            $running = $running->plus($delta);
            $totalDebit = $totalDebit->plus((string) $line->debit);
            $totalCredit = $totalCredit->plus((string) $line->credit);
            $rows[] = [
                'date' => substr((string) $line->transaction_date, 0, 10),
                'voucher_no' => $line->batch_number, 'voucher_type' => $line->voucher_type,
                'particulars' => $line->line_comments ?? $line->voucher_comments,
                'debit' => (string) BigDecimal::of((string) $line->debit)->toScale(2),
                'credit' => (string) BigDecimal::of((string) $line->credit)->toScale(2),
                'balance' => (string) $running->toScale(2),
            ];
        }

        return [
            'ledger' => ['id' => $ledger->id, 'code' => $ledger->ledger_code, 'name' => $ledger->name_en, 'group' => $ledger->ledger_group],
            'opening' => $opening, 'rows' => $rows,
            'total_debit' => (string) $totalDebit->toScale(2), 'total_credit' => (string) $totalCredit->toScale(2),
            'closing' => (string) $running->toScale(2),
        ];
    }

    /** Cash book = the Cash-in-Hand ledger statement plus day totals. */
    public function cashBook(array $branchIds, string $from, string $to): array
    {
        $cash = Ledger::where('ledger_code', AccountsService::CASH_IN_HAND)->firstOrFail();

        return $this->ledgerStatement($branchIds, $cash, $from, $to);
    }

    /** One day's cash position for ONE branch + per-cashier movement. */
    public function dailyCashBook(int $branchId, string $date): array
    {
        $cash = Ledger::where('ledger_code', AccountsService::CASH_IN_HAND)->firstOrFail();
        $opening = $this->balanceUpTo([$branchId], $cash, $date, true);
        $day = $this->sums([$branchId], $date, $date);
        $s = $day[$cash->id] ?? ['debit' => '0', 'credit' => '0'];
        $closing = (string) BigDecimal::of($opening)->plus($s['debit'])->minus($s['credit'])->toScale(2);

        $cashierRows = AccountTransaction::query()
            ->where('branch_id', $branchId)->where('account_type', 'cash')
            ->where('business_date', $date)->whereNull('cancel_at')
            ->selectRaw('account_id, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS in_sum, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS out_sum')
            ->groupBy('account_id')->get();
        $users = \App\Models\User::whereIn('id', $cashierRows->pluck('account_id'))->get()->keyBy('id');
        $cashiers = [];
        foreach ($cashierRows as $row) {
            // Expected balance at end of that date = last balance_after.
            $last = AccountTransaction::where('branch_id', $branchId)->where('account_type', 'cash')
                ->where('account_id', $row->account_id)->where('business_date', '<=', $date)
                ->whereNull('cancel_at')->orderByDesc('id')->first();
            $cashiers[] = [
                'user_id' => (int) $row->account_id,
                'name' => $users[$row->account_id]->name ?? ('#'.$row->account_id),
                'cash_in' => (string) BigDecimal::of((string) $row->in_sum)->toScale(2),
                'cash_out' => (string) BigDecimal::of((string) $row->out_sum)->toScale(2),
                'balance' => $last ? (string) $last->balance_after : '0.00',
            ];
        }

        return ['opening' => $opening, 'receipts' => (string) BigDecimal::of($s['debit'])->toScale(2),
            'payments' => (string) BigDecimal::of($s['credit'])->toScale(2), 'closing' => $closing,
            'cashiers' => $cashiers];
    }

    /** Cash sub-ledger detail rows (officer cash movements). */
    public function cashTransactionDetails(array $branchIds, ?int $userId, string $from, string $to): array
    {
        $query = AccountTransaction::query()
            ->join('vouchers', 'vouchers.id', '=', 'account_transactions.voucher_id')
            ->leftJoin('users', 'users.id', '=', 'account_transactions.account_id')
            ->whereIn('account_transactions.branch_id', $branchIds)
            ->where('account_transactions.account_type', 'cash')
            ->whereBetween('account_transactions.tran_date', [$from, $to])
            ->orderBy('account_transactions.tran_date')->orderBy('account_transactions.id')
            ->select('account_transactions.*', 'vouchers.batch_number', 'users.name AS user_name');
        if ($userId) {
            $query->where('account_transactions.account_id', $userId);
        }

        return $query->get()->map(fn ($row) => [
            'date' => $row->tran_date->toDateString(), 'voucher_no' => $row->batch_number,
            'user' => $row->user_name ?? ('#'.$row->account_id), 'tran_code' => $row->tran_code,
            'amount' => (string) $row->amount, 'balance_after' => (string) $row->balance_after,
            'cancelled' => $row->cancel_at !== null,
        ])->all();
    }

    // -- Sub-ledger statements ----------------------------------------------

    /** Statement over account_transactions for one sub-ledger account. */
    public function accountStatement(string $accountType, int $accountId, string $from, string $to): array
    {
        $base = AccountTransaction::where('account_type', $accountType)->where('account_id', $accountId)
            ->whereNull('cancel_at');
        $prior = (clone $base)->where('tran_date', '<', $from)->orderByDesc('id')->first();
        $opening = $prior ? (string) $prior->balance_after : '0.00';
        $rows = (clone $base)->whereBetween('tran_date', [$from, $to])
            ->orderBy('tran_date')->orderBy('id')->get()
            ->map(fn (AccountTransaction $row) => [
                'date' => $row->tran_date->toDateString(), 'tran_code' => $row->tran_code,
                'amount' => (string) $row->amount, 'balance_after' => (string) $row->balance_after,
                'voucher_id' => $row->voucher_id,
            ])->all();
        $closing = $rows === [] ? $opening : end($rows)['balance_after'];

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $closing];
    }

    /** A member's combined statement: savings/DPS/FDR accounts, loans, share. */
    public function customerStatement(int $memberId, string $from, string $to): array
    {
        $member = Member::with('samity')->findOrFail($memberId);
        $deposits = DepositAccount::where('member_id', $memberId)->orderBy('id')->get();
        $loans = LoanAccount::where('member_id', $memberId)->orderBy('id')->get();

        $targets = [];
        foreach ($deposits as $account) {
            $targets[] = ['deposit', $account->id, 'Deposit '.$account->account_number];
        }
        foreach ($loans as $loan) {
            $targets[] = ['loan', $loan->id, 'Loan '.$loan->loan_number];
        }
        $targets[] = ['share', $memberId, 'Share Capital'];

        $rows = [];
        foreach ($targets as [$type, $id, $label]) {
            $statement = $this->accountStatement($type, $id, $from, $to);
            foreach ($statement['rows'] as $row) {
                $rows[] = $row + ['account' => $label];
            }
        }
        usort($rows, fn ($a, $b) => [$a['date'], $a['voucher_id']] <=> [$b['date'], $b['voucher_id']]);

        return [
            'member' => ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name,
                'samity' => $member->samity?->name, 'mobile' => $member->mobile],
            'deposits' => $deposits->map(fn (DepositAccount $a) => [
                'account_number' => $a->account_number, 'balance' => (string) $a->current_balance,
            ])->all(),
            'loans' => $loans->map(fn (LoanAccount $l) => [
                'account_number' => $l->loan_number, 'status' => $l->status,
            ])->all(),
            'share_amount' => (string) $member->share_amount,
            'rows' => $rows,
        ];
    }

    // -- Receipt & payment ----------------------------------------------------

    /**
     * Receipt & Payment over cash+bank: every non-cancelled voucher in
     * the period that touches Cash-in-Hand/Cash-at-Bank contributes its
     * NET cash movement, classified under its dominant counterpart
     * ledger. opening + receipts − payments = closing by construction.
     */
    public function receiptPayment(array $branchIds, string $from, string $to, bool $grouped = false): array
    {
        $cashLedgers = Ledger::whereIn('ledger_code', [AccountsService::CASH_IN_HAND, AccountsService::CASH_AT_BANK])->pluck('id')->all();
        $opening = '0.00';
        foreach ($cashLedgers as $lid) {
            $ledger = Ledger::find($lid);
            $opening = (string) BigDecimal::of($opening)->plus($this->balanceUpTo($branchIds, $ledger, $from, true))->toScale(2);
        }

        $lines = VoucherLine::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_lines.voucher_id')
            ->whereIn('vouchers.branch_id', $branchIds)
            ->where('vouchers.status', '!=', 'Cancelled')
            ->whereBetween('vouchers.transaction_date', [$from, $to])
            ->select('voucher_lines.voucher_id', 'voucher_lines.ledger_id', 'voucher_lines.debit', 'voucher_lines.credit')
            ->get()->groupBy('voucher_id');

        $receipts = [];
        $payments = [];
        foreach ($lines as $voucherLines) {
            $net = BigDecimal::zero();
            $counter = [];
            foreach ($voucherLines as $line) {
                $d = BigDecimal::of((string) $line->debit);
                $c = BigDecimal::of((string) $line->credit);
                if (in_array((int) $line->ledger_id, $cashLedgers, true)) {
                    $net = $net->plus($d)->minus($c);
                } else {
                    $counter[(int) $line->ledger_id] = ($counter[(int) $line->ledger_id] ?? BigDecimal::zero())->plus($c)->minus($d);
                }
            }
            if ($net->isZero() || $counter === []) {
                continue;
            }
            // Dominant counterpart = the ledger carrying the largest
            // share of the offsetting side.
            uasort($counter, fn ($a, $b) => $b->abs() <=> $a->abs());
            $headId = array_key_first($counter);
            if ($net->isPositive()) {
                $receipts[$headId] = ($receipts[$headId] ?? BigDecimal::zero())->plus($net);
            } else {
                $payments[$headId] = ($payments[$headId] ?? BigDecimal::zero())->plus($net->abs());
            }
        }

        $present = function (array $bucket) {
            $ledgers = Ledger::whereIn('id', array_keys($bucket))->get()->keyBy('id');
            $rows = [];
            foreach ($bucket as $lid => $amount) {
                $ledger = $ledgers[$lid];
                $rows[] = ['ledger_id' => $lid, 'code' => $ledger->ledger_code, 'name' => $ledger->name_en,
                    'group' => $ledger->ledger_group, 'amount' => (string) $amount->toScale(2)];
            }
            usort($rows, fn ($a, $b) => BigDecimal::of($b['amount']) <=> BigDecimal::of($a['amount']));

            return $rows;
        };

        $receiptRows = $present($receipts);
        $paymentRows = $present($payments);
        $totalReceipts = collect($receiptRows)->reduce(fn ($carry, $r) => $carry->plus($r['amount']), BigDecimal::zero());
        $totalPayments = collect($paymentRows)->reduce(fn ($carry, $r) => $carry->plus($r['amount']), BigDecimal::zero());
        $closing = (string) BigDecimal::of($opening)->plus($totalReceipts)->minus($totalPayments)->toScale(2);

        $result = [
            'opening' => $opening, 'receipts' => $receiptRows, 'payments' => $paymentRows,
            'total_receipts' => (string) $totalReceipts->toScale(2),
            'total_payments' => (string) $totalPayments->toScale(2), 'closing' => $closing,
        ];
        if ($grouped) {
            $result['receipt_groups'] = $this->groupRows($receiptRows);
            $result['payment_groups'] = $this->groupRows($paymentRows);
        }

        return $result;
    }

    private function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['group']]['group'] = $row['group'];
            $groups[$row['group']]['rows'][] = $row;
            $groups[$row['group']]['total'] = (string) BigDecimal::of($groups[$row['group']]['total'] ?? '0')->plus($row['amount'])->toScale(2);
        }

        return array_values($groups);
    }

    // -- Income & expense / balance sheet -------------------------------------

    public function incomeExpense(array $branchIds, string $from, string $to): array
    {
        $rows = $this->ledgerBalances($branchIds, $from, $to);
        $income = [];
        $expense = [];
        foreach ($rows as $row) {
            if ($row['group'] === 'Income') {
                $income[] = $row;
            } elseif ($row['group'] === 'Expense') {
                $expense[] = $row;
            }
        }
        $totalIncome = collect($income)->reduce(fn ($c, $r) => $c->plus($r['balance']), BigDecimal::zero());
        $totalExpense = collect($expense)->reduce(fn ($c, $r) => $c->plus($r['balance']), BigDecimal::zero());

        return ['income' => $income, 'expense' => $expense,
            'total_income' => (string) $totalIncome->toScale(2),
            'total_expense' => (string) $totalExpense->toScale(2),
            'surplus' => (string) $totalIncome->minus($totalExpense)->toScale(2)];
    }

    /** Cumulative surplus (income − expense) up to a date. */
    public function surplusUpTo(array $branchIds, string $to): string
    {
        $rows = $this->ledgerBalances($branchIds, null, $to);
        $surplus = BigDecimal::zero();
        foreach ($rows as $row) {
            if ($row['group'] === 'Income') {
                $surplus = $surplus->plus($row['balance']);
            } elseif ($row['group'] === 'Expense') {
                $surplus = $surplus->minus($row['balance']);
            }
        }

        return (string) $surplus->toScale(2);
    }

    public function balanceSheet(array $branchIds, string $asOf): array
    {
        $rows = $this->ledgerBalances($branchIds, null, $asOf);
        $sections = ['Asset' => [], 'Liability' => [], 'Equity' => []];
        foreach ($rows as $row) {
            if (isset($sections[$row['group']]) && ! BigDecimal::of($row['balance'])->isZero()) {
                $sections[$row['group']][] = $row;
            }
        }
        $totals = [];
        foreach ($sections as $group => $sectionRows) {
            $totals[$group] = (string) collect($sectionRows)->reduce(fn ($c, $r) => $c->plus($r['balance']), BigDecimal::zero())->toScale(2);
        }
        $surplus = $this->surplusUpTo($branchIds, $asOf);
        $totalLiabEquity = (string) BigDecimal::of($totals['Liability'])->plus($totals['Equity'])->plus($surplus)->toScale(2);

        return ['sections' => $sections, 'totals' => $totals, 'surplus' => $surplus,
            'total_assets' => $totals['Asset'], 'total_liab_equity' => $totalLiabEquity,
            'balanced' => BigDecimal::of($totals['Asset'])->isEqualTo(BigDecimal::of($totalLiabEquity))];
    }

    /** Per-branch columns version (Balance Sheet II / Income & Expenses II). */
    public function byBranch(string $kind, array $branchIds, ?string $from, string $to): array
    {
        $columns = [];
        foreach ($branchIds as $branchId) {
            $columns[$branchId] = $kind === 'balance_sheet'
                ? $this->balanceSheet([$branchId], $to)
                : $this->incomeExpense([$branchId], $from ?? $to, $to);
        }

        return ['branches' => $branchIds, 'columns' => $columns,
            'total' => $kind === 'balance_sheet' ? $this->balanceSheet($branchIds, $to) : $this->incomeExpense($branchIds, $from ?? $to, $to)];
    }

    /** Account Receipt Payment: per-ledger total in/out over the period (all ledgers). */
    public function accountReceiptPayment(array $branchIds, string $from, string $to): array
    {
        return $this->ledgerBalances($branchIds, $from, $to);
    }
}
