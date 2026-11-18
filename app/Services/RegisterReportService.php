<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\BusinessDay;
use App\Models\DepositAccount;
use App\Models\DepositTransaction;
use App\Models\DuePenaltySetup;
use App\Models\Ledger;
use App\Models\LoanAccount;
use App\Models\LoanFee;
use App\Models\LoanReceive;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\MemberClosing;
use App\Models\Samity;
use App\Models\Scopes\SamityScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Client round 3 (2026-11-18) — the two paper registers, computed
 * from the same dated sources as every other report (never typed in):
 *
 *  A) দৈনিক অফিস তথ্য রেজিস্ট্রার — the manager's daily register:
 *     one row per BUSINESS DAY of a branch + month, grouped in
 *     6-business-day weeks; each week closes with a মোট row (the
 *     week) and a সর্বমোট row (cumulative from the month's first
 *     business day through that week — the last one is the month
 *     total), exactly as the paper sheet repeats them. The print
 *     views replicate the client's sheet column-for-column (25
 *     columns incl. ক্রমিক নং and the ম্যানেজারের স্বাক্ষর column).
 *     at the end, in the client's sheet column order.
 *
 *  B) সাপ্তাহিক স্থিতির হিসাবের সিট — the weekly balance sheet:
 *     one row per samity of a branch for a 6-business-day week.
 *
 * Both are BRANCH reports: like the Director dashboard they read the
 * whole branch, so member queries run without the SamityScope (the
 * strict ticked-samity rule governs working pages, not this register;
 * branch access itself is enforced by the controller).
 *
 * FIGURE DEFINITIONS (Part A, per branch + business day D):
 *  - সঞ্চয় যোগ্য: Σ installment_amount over the branch's deposit
 *    accounts active on D (opened on/before D, not closed before D) —
 *    the same expected-amount source the collection sheet prefills
 *    (CollectionService::depositReceivable). Products without a
 *    scheduled installment (general savings with no installment set)
 *    contribute 0.
 *  - সঞ্চয় কৃত: deposit_transactions Receive posted on D,
 *    cancellations excluded — the DCR/Director definition, so the
 *    loan-linked 10% savings credit counts, by design.
 *  - সদস্য ভর্তি জন: members whose open_date = D. টাকা: the share
 *    money received at those admissions — account_transactions
 *    SHARE_RECEIVE rows dated D for members admitted on D (admission
 *    posts exactly one such row per member, see
 *    MemberAdmissionService::postAdmissionShare).
 *  - ঋণ বীমা: loan_fees of type 'insurance' whose opening Receipt
 *    voucher is dated D (জন = distinct loans, টাকা = Σ amount).
 *  - অন্যান্য (fees): every other loan_fees type collected the same
 *    way on D (form/health/risk/document/emi_locker/others/custom).
 *  - উত্তোলন: deposit_transactions Payment on D (জন = distinct
 *    members, টাকা = Σ amount) — office payments AND the new field
 *    withdrawals land on this same table.
 *  - সদস্য ছাড়া জন: member_closings (not cancelled) dated D.
 *  - প্রথম মোট = সঞ্চয় কৃত + ভর্তি টাকা + বীমা টাকা + অন্যান্য
 *    − উত্তোলন টাকা (net cash in from member operations).
 *  - প্রদেয় ঋণ: loans disbursed on D (disbursed_at date = D, the
 *    Director definition; জন = count, টাকা = Σ principal).
 *  - পরিশোধ: loans whose repayment SETTLED them on D — cumulative
 *    principal+profit received reaches the loan's total payable
 *    (Σ schedule principal_due + profit_due) on D and had not
 *    reached it before D (জন = count, টাকা = that day's total
 *    received against those loans, installment_amount incl. any
 *    penalty part).
 *  - কিস্তি আদায় যোগ্য: the Daily Collection Rule figure for D,
 *    reconstructed historically from loan_receive_allocations:
 *    Σ over schedule rows due on/before D (loans disbursed
 *    on/before D) of the principal+profit still unpaid at the START
 *    of D, plus the per-installment penalty charge
 *    (DuePenaltySetup) for rows overdue at the start of D, net of
 *    penalty already paid — the LoanMoneyService::todaysDue /
 *    penaltyDue rule, as of D. (Step-19 extra LoanPenalty charges
 *    and waivers are live adjustments and are not reconstructed.)
 *  - কিস্তি আদায় কৃত: loan_receives installment_amount on D,
 *    EXCLUDING the receives that settled a loan on D — settlement
 *    money is reported once, under পরিশোধ, so the second মোট
 *    stays the day's true loan cash-in (no double count).
 *  - দ্বিতীয় মোট = পরিশোধ টাকা + কিস্তি কৃত. সর্বমোট (per day) =
 *    প্রথম মোট + দ্বিতীয় মোট.
 *  - ঋণের স্থিতি: loan outstanding at END of D on the Director
 *    basis (principal + profit remaining): Σ over loans disbursed
 *    on/before D of max(0, total payable − principal+profit
 *    received on/before D). Penalty is excluded — it is assessed
 *    by rule at collection time, not carried as a balance.
 *  - অন্যান্য ব্যয়: the branch's Expense-ledger postings on D
 *    (AccountReportService::incomeExpense total_expense — loan
 *    disbursements and deposit payments never touch Expense
 *    ledgers, so they are excluded by construction).
 *  - হাতে নগদ: Cash-in-Hand ledger (1010) balance for the branch
 *    at end of D (AccountReportService::balanceUpTo — the cash
 *    book basis).
 *  Week মোট / month সর্বমোট rows sum the flow columns; the two
 *  balance columns (ঋণের স্থিতি, হাতে নগদ) carry the LAST day's
 *  value, as a balance must.
 *
 * FIGURE DEFINITIONS (Part B, per samity, week = the 6 business
 * days ending at the chosen date):
 *  - সঞ্চয় পূর্বের স্থিতি: signed Σ of deposit account_transactions
 *    (the sub-ledger source of truth) dated BEFORE the week, over
 *    every deposit account of the samity's members.
 *  - সাপ্তাহিক জের: deposit Receives during the week (DCR source).
 *  - উত্তোলন: deposit Payments during the week (জন/টাকা).
 *  - মোট স্থিতি: the same signed Σ dated on/before the week-end —
 *    the true ledger balance. It equals পূর্বের + জের − উত্তোলন
 *    whenever the week's only movements are receives and payments
 *    (the client's own example: 1,00,000 + 20,000 − 10,000 =
 *    1,10,000); a mid-week profit credit would sit inside মোট
 *    স্থিতি without passing through জের.
 *  - কিস্তি পূর্বের জের: principal+profit outstanding at week
 *    start (same reconstruction as ঋণের স্থিতি, before the week).
 *  - সাপ্তাহিক জের: principal+profit received during the week.
 *  - লোন বিতরণ: loans disbursed during the week (জন = count,
 *    টাকা = their total repayable principal+profit — the amount
 *    that entered the outstanding balance, so the sheet's own
 *    identity মোট স্থিতি = পূর্বের − আদায় + বিতরণ holds exactly;
 *    the daily register's প্রদেয় ঋণ টাকা stays principal-only).
 *  - মোট স্থিতি: principal+profit outstanding at week end.
 */
class RegisterReportService
{
    public function __construct(private AccountReportService $accounts) {}

    private function bd(mixed $v): BigDecimal
    {
        return BigDecimal::of((string) ($v === null || $v === '' ? 0 : $v));
    }

    private function money(mixed $v): string
    {
        return (string) $this->bd($v)->toScale(2, RoundingMode::HalfUp);
    }

    /** @return string[] business dates of the branch in [$from, $to], ascending */
    public function businessDaysBetween(int $branchId, string $from, string $to): array
    {
        return BusinessDay::where('branch_id', $branchId)
            ->whereBetween('business_date', [$from, $to])
            ->orderBy('business_date')
            ->pluck('business_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();
    }

    /**
     * The 6 business days ending at (and including) $endDate for the
     * branch: [weekStart, weekEnd] as date strings, or null when the
     * branch has no business day on/before the date.
     *
     * @return array{0: string, 1: string, days: string[]}|null
     */
    public function weekEnding(int $branchId, string $endDate): ?array
    {
        $days = BusinessDay::where('branch_id', $branchId)
            ->where('business_date', '<=', $endDate)
            ->orderByDesc('business_date')
            ->limit(6)
            ->pluck('business_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();
        if ($days === []) {
            return null;
        }

        return [end($days), $days[0], 'days' => array_reverse($days)];
    }

    // =====================================================================
    // Part A — daily register
    // =====================================================================

    /**
     * @return array{weeks: array<int, array{rows: array<int, array<string, mixed>>, total: array<string, mixed>}>, grand: array<string, mixed>, days: string[]}
     */
    public function dailyRegister(int $branchId, string $month): array
    {
        $monthStart = $month.'-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $days = $this->businessDaysBetween($branchId, $monthStart, $monthEnd);
        if ($days === []) {
            return ['weeks' => [], 'grand' => $this->zeroRow(''), 'days' => []];
        }
        $first = $days[0];
        $last = $days[count($days) - 1];

        // --- whole-month aggregates, bucketed by business date --------
        $depReceive = $this->sumByDate(
            DepositTransaction::where('branch_id', $branchId)->where('tran_type', 'Receive')
                ->whereNull('cancel_at')->whereBetween('business_date', [$first, $last]),
            'business_date', 'amount'
        );
        $depPayment = DepositTransaction::where('branch_id', $branchId)->where('tran_type', 'Payment')
            ->whereNull('cancel_at')->whereBetween('business_date', [$first, $last])
            ->selectRaw('business_date, COUNT(DISTINCT member_id) AS members, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('business_date')->get()
            ->mapWithKeys(fn ($r) => [$r->business_date->toDateString() => ['count' => (int) $r->members, 'total' => $this->money($r->total)]])->all();
        $admissions = Member::withoutGlobalScope(SamityScope::class)
            ->where('branch_id', $branchId)->whereBetween('open_date', [$first, $last])
            ->selectRaw('open_date, COUNT(*) AS members')->groupBy('open_date')->get()
            ->mapWithKeys(fn ($r) => [$r->open_date->toDateString() => (int) $r->members])->all();
        $admissionShare = DB::table('account_transactions')
            ->join('members', 'members.id', '=', 'account_transactions.account_id')
            ->where('account_transactions.account_type', 'share')
            ->where('account_transactions.tran_code', 'SHARE_RECEIVE')
            ->whereNull('account_transactions.cancel_at')
            ->where('members.branch_id', $branchId)
            ->whereBetween('account_transactions.business_date', [$first, $last])
            ->whereColumn('members.open_date', 'account_transactions.business_date')
            ->selectRaw('account_transactions.business_date AS d, COALESCE(SUM(account_transactions.amount), 0) AS total')
            ->groupBy('account_transactions.business_date')->get()
            ->mapWithKeys(fn ($r) => [$r->d => $this->money($r->total)])->all();
        $fees = LoanFee::query()
            ->join('vouchers', 'vouchers.id', '=', 'loan_fees.voucher_id')
            ->where('vouchers.branch_id', $branchId)->where('vouchers.status', '!=', 'Cancelled')
            ->whereBetween('vouchers.business_date', [$first, $last])
            ->selectRaw('vouchers.business_date AS d, loan_fees.fee_type AS t, COUNT(DISTINCT loan_fees.loan_account_id) AS loans, COALESCE(SUM(loan_fees.amount), 0) AS total')
            ->groupBy('vouchers.business_date', 'loan_fees.fee_type')->get();
        $insuranceByDate = [];
        $otherFeesByDate = [];
        foreach ($fees as $fee) {
            $d = $fee->d instanceof \DateTimeInterface ? $fee->d->format('Y-m-d') : (string) $fee->d;
            if ($fee->t === 'insurance') {
                $insuranceByDate[$d] = ['count' => (int) $fee->loans, 'total' => $this->money($fee->total)];
            } else {
                $cur = $otherFeesByDate[$d] ?? '0.00';
                $otherFeesByDate[$d] = $this->money($this->bd($cur)->plus((string) $fee->total));
            }
        }
        $exits = MemberClosing::query()
            ->join('members', 'members.id', '=', 'member_closings.member_id')
            ->where('members.branch_id', $branchId)->whereNull('member_closings.cancelled_at')
            ->whereBetween('member_closings.closing_date', [$first, $last])
            ->selectRaw('member_closings.closing_date AS d, COUNT(*) AS members')
            ->groupBy('member_closings.closing_date')->get()
            ->mapWithKeys(fn ($r) => [($r->d instanceof \DateTimeInterface ? $r->d->format('Y-m-d') : (string) $r->d) => (int) $r->members])->all();
        $disbursed = LoanAccount::where('branch_id', $branchId)->whereNotNull('disbursed_at')
            ->whereDate('disbursed_at', '>=', $first)->whereDate('disbursed_at', '<=', $last)
            ->selectRaw('DATE(disbursed_at) AS d, COUNT(*) AS loans, COALESCE(SUM(loan_amount), 0) AS total')
            ->groupByRaw('DATE(disbursed_at)')->get()
            ->mapWithKeys(fn ($r) => [$r->d => ['count' => (int) $r->loans, 'total' => $this->money($r->total)]])->all();
        $installmentCollected = $this->sumByDate(
            LoanReceive::query()->join('loan_accounts', 'loan_accounts.id', '=', 'loan_receives.loan_account_id')
                ->where('loan_accounts.branch_id', $branchId)->whereNull('loan_receives.cancel_at')
                ->whereBetween('loan_receives.business_date', [$first, $last]),
            'loan_receives.business_date', 'loan_receives.installment_amount'
        );

        // --- loan history (payable, allocations, receives) ------------
        $history = $this->loanHistory($branchId, $last);
        $accounts = DepositAccount::where('branch_id', $branchId)->get();
        $cashLedger = Ledger::where('ledger_code', AccountsService::CASH_IN_HAND)->first();

        $rows = [];
        foreach ($days as $date) {
            $savingsDue = BigDecimal::zero();
            foreach ($accounts as $account) {
                $opened = $account->opening_date && $account->opening_date->toDateString() <= $date;
                $notClosed = $account->closing_date === null || $account->closing_date->toDateString() >= $date;
                if ($opened && $notClosed) {
                    $savingsDue = $savingsDue->plus((string) ($account->installment_amount ?? 0));
                }
            }
            $savingsCollected = $this->bd($depReceive[$date] ?? 0);
            $admissionAmount = $this->bd($admissionShare[$date] ?? 0);
            $insurance = $insuranceByDate[$date] ?? ['count' => 0, 'total' => '0.00'];
            $otherFees = $this->bd($otherFeesByDate[$date] ?? 0);
            $withdrawal = $depPayment[$date] ?? ['count' => 0, 'total' => '0.00'];
            $totalIn = $savingsCollected->plus($admissionAmount)->plus((string) $insurance['total'])
                ->plus($otherFees)->minus((string) $withdrawal['total']);
            $disb = $disbursed[$date] ?? ['count' => 0, 'total' => '0.00'];
            $settled = $history['settled'][$date] ?? ['count' => 0, 'total' => '0.00'];
            // Settlement receives are reported under পরিশোধ only —
            // net them out of the installment column (see docblock).
            $installmentIn = $this->bd($installmentCollected[$date] ?? 0)->minus((string) $settled['total']);
            if ($installmentIn->isNegative()) {
                $installmentIn = BigDecimal::zero();
            }
            $totalLoan = $this->bd($settled['total'])->plus($installmentIn);
            $expense = $this->accounts->incomeExpense([$branchId], $date, $date)['total_expense'];
            $cash = $cashLedger ? $this->accounts->balanceUpTo([$branchId], $cashLedger, $date) : '0.00';

            $rows[] = [
                'date' => $date,
                'savings_due' => $this->money($savingsDue),
                'savings_collected' => $this->money($savingsCollected),
                'admission_count' => $admissions[$date] ?? 0,
                'admission_amount' => $this->money($admissionAmount),
                'insurance_count' => $insurance['count'],
                'insurance_amount' => $this->money($insurance['total']),
                'withdrawal_count' => $withdrawal['count'],
                'withdrawal_amount' => $this->money($withdrawal['total']),
                'member_exit_count' => $exits[$date] ?? 0,
                'other_fees' => $this->money($otherFees),
                'total_in' => $this->money($totalIn),
                'disbursed_count' => $disb['count'],
                'disbursed_amount' => $this->money($disb['total']),
                'settled_count' => $settled['count'],
                'settled_amount' => $this->money($settled['total']),
                'installment_due' => $history['due'][$date] ?? '0.00',
                'installment_collected' => $this->money($installmentIn),
                'total_loan' => $this->money($totalLoan),
                'grand_total' => $this->money($totalIn->plus($totalLoan)),
                'loan_outstanding' => $history['outstanding'][$date] ?? '0.00',
                'other_expenses' => $this->money($expense),
                'cash_in_hand' => $this->money($cash),
            ];
        }

        // The sheet's rhythm: each 6-day block closes with a মোট row
        // (that week) and a সর্বমোট row (CUMULATIVE from the month's
        // first day through that week); the last সর্বমোট is the
        // month's grand total.
        $weeks = [];
        $seen = [];
        foreach (array_chunk($rows, 6) as $chunk) {
            $seen = [...$seen, ...$chunk];
            $weeks[] = [
                'rows' => $chunk,
                'total' => $this->totalRow($chunk),
                'cumulative' => $this->totalRow($seen),
            ];
        }

        return ['weeks' => $weeks, 'grand' => $this->totalRow($rows), 'days' => $days];
    }

    // =====================================================================
    // Part B — weekly balance sheet
    // =====================================================================

    /**
     * @return array{week: ?array{start: string, end: string, days: string[]}, savings: array<int, array<string, mixed>>, savings_total: array<string, mixed>, loans: array<int, array<string, mixed>>, loans_total: array<string, mixed>}
     */
    public function weeklyBalance(int $branchId, string $endDate): array
    {
        $week = $this->weekEnding($branchId, $endDate);
        $empty = ['week' => null, 'savings' => [], 'savings_total' => [], 'loans' => [], 'loans_total' => []];
        if ($week === null) {
            return $empty;
        }
        [$start, $end] = [$week[0], $week[1]];

        $history = $this->loanHistory($branchId, $end);
        $samities = Samity::where('branch_id', $branchId)->orderBy('samity_code')->get();

        $savingsRows = [];
        $loanRows = [];
        foreach ($samities as $samity) {
            $memberIds = Member::withoutGlobalScope(SamityScope::class)
                ->where('samity_id', $samity->id)->pluck('id')->all();
            $accountIds = $memberIds === [] ? [] : DepositAccount::whereIn('member_id', $memberIds)->pluck('id')->all();

            $signedBefore = $accountIds === [] ? '0' : AccountTransaction::where('account_type', 'deposit')
                ->whereIn('account_id', $accountIds)->whereNull('cancel_at')
                ->where('business_date', '<', $start)->sum('amount');
            $signedUpto = $accountIds === [] ? '0' : AccountTransaction::where('account_type', 'deposit')
                ->whereIn('account_id', $accountIds)->whereNull('cancel_at')
                ->where('business_date', '<=', $end)->sum('amount');
            $receivedWeek = $memberIds === [] ? '0' : DepositTransaction::where('tran_type', 'Receive')
                ->whereNull('cancel_at')->whereIn('member_id', $memberIds)
                ->whereBetween('business_date', [$start, $end])->sum('amount');
            $paidWeek = $memberIds === [] ? null : DepositTransaction::where('tran_type', 'Payment')
                ->whereNull('cancel_at')->whereIn('member_id', $memberIds)
                ->whereBetween('business_date', [$start, $end])
                ->selectRaw('COUNT(DISTINCT member_id) AS members, COALESCE(SUM(amount), 0) AS total')->first();

            $savingsRows[] = [
                'samity_id' => $samity->id,
                'samity_code' => $samity->samity_code,
                'samity_name' => $samity->name,
                'previous_balance' => $this->money($signedBefore),
                'weekly_collection' => $this->money($receivedWeek),
                'withdrawal_count' => (int) ($paidWeek->members ?? 0),
                'withdrawal_amount' => $this->money($paidWeek->total ?? 0),
                'total_balance' => $this->money($signedUpto),
            ];

            // Loan side from the shared loan-history reconstruction,
            // restricted to this samity's loans.
            $loanIds = $memberIds === [] ? [] : LoanAccount::whereIn('member_id', $memberIds)
                ->whereNotNull('disbursed_at')->pluck('id')->all();
            $previous = BigDecimal::zero();
            $collectedWeek = BigDecimal::zero();
            $disbursedWeekCount = 0;
            $disbursedWeek = BigDecimal::zero();
            $totalOutstanding = BigDecimal::zero();
            foreach ($loanIds as $loanId) {
                $loan = $history['loans'][$loanId] ?? null;
                if ($loan === null) {
                    continue;
                }
                if ($loan['disbursed_on'] < $start) {
                    $previous = $previous->plus($this->bd($loan['payable'])->minus($this->receivedBefore($loan, $start)));
                }
                if ($loan['disbursed_on'] <= $end) {
                    $totalOutstanding = $totalOutstanding->plus($this->bd($loan['payable'])->minus($this->receivedUpto($loan, $end)));
                }
                if ($loan['disbursed_on'] >= $start && $loan['disbursed_on'] <= $end) {
                    $disbursedWeekCount++;
                    $disbursedWeek = $disbursedWeek->plus($loan['payable']);
                }
                foreach ($loan['receives'] as $receive) {
                    if ($receive['date'] >= $start && $receive['date'] <= $end) {
                        $collectedWeek = $collectedWeek->plus($receive['principal_profit']);
                    }
                }
            }
            $loanRows[] = [
                'samity_id' => $samity->id,
                'samity_code' => $samity->samity_code,
                'samity_name' => $samity->name,
                'previous_dues' => $this->money($previous),
                'weekly_collection' => $this->money($collectedWeek),
                'disbursed_count' => $disbursedWeekCount,
                'disbursed_amount' => $this->money($disbursedWeek),
                'total_outstanding' => $this->money($totalOutstanding),
            ];
        }

        return [
            'week' => ['start' => $start, 'end' => $end, 'days' => $week['days']],
            'savings' => $savingsRows,
            'savings_total' => $this->sumRows($savingsRows, ['previous_balance', 'weekly_collection', 'withdrawal_amount', 'total_balance'], ['withdrawal_count']),
            'loans' => $loanRows,
            'loans_total' => $this->sumRows($loanRows, ['previous_dues', 'weekly_collection', 'disbursed_amount', 'total_outstanding'], ['disbursed_count']),
        ];
    }

    // =====================================================================
    // Shared loan-history reconstruction
    // =====================================================================

    /**
     * Per-loan dated history for one branch, up to $upto (inclusive):
     * total payable (principal+profit of the live schedule), every
     * live receive (date, installment total, principal+profit part),
     * plus per-date aggregates for the daily register: installment
     * due at start of day, outstanding at end of day, and the loans
     * settled on each date.
     *
     * @return array{loans: array<int, array{disbursed_on: string, payable: BigDecimal, receives: array<int, array{date: string, total: BigDecimal, principal_profit: BigDecimal}>}>, due: array<string, string>, outstanding: array<string, string>, settled: array<string, array{count: int, total: string}>}
     */
    private function loanHistory(int $branchId, string $upto): array
    {
        $loans = LoanAccount::where('branch_id', $branchId)->whereNotNull('disbursed_at')
            ->whereDate('disbursed_at', '<=', $upto)->get();
        $loanIds = $loans->pluck('id')->all();

        $payable = [];
        if ($loanIds !== []) {
            $payable = LoanSchedule::whereIn('loan_account_id', $loanIds)->whereNull('superseded_by')
                ->selectRaw('loan_account_id, COALESCE(SUM(principal_due + profit_due), 0) AS total')
                ->groupBy('loan_account_id')->pluck('total', 'loan_account_id')->all();
        }

        $receives = [];
        if ($loanIds !== []) {
            $rows = LoanReceive::whereIn('loan_account_id', $loanIds)->whereNull('cancel_at')
                ->where('business_date', '<=', $upto)
                ->orderBy('business_date')->orderBy('id')->get();
            foreach ($rows as $row) {
                $receives[$row->loan_account_id][] = [
                    'date' => $row->business_date->toDateString(),
                    'total' => $this->bd($row->installment_amount),
                    'principal_profit' => $this->bd($row->investment_amount)->plus((string) $row->profit_amount),
                ];
            }
        }

        // Allocations per schedule row, bucketed by the receive's
        // business date, for the start-of-day due reconstruction.
        $allocBySchedule = [];
        if ($loanIds !== []) {
            $allocs = DB::table('loan_receive_allocations')
                ->join('loan_receives', 'loan_receives.id', '=', 'loan_receive_allocations.loan_receive_id')
                ->join('loan_schedules', 'loan_schedules.id', '=', 'loan_receive_allocations.loan_schedule_id')
                ->whereIn('loan_schedules.loan_account_id', $loanIds)
                ->whereNull('loan_receives.cancel_at')
                ->where('loan_receives.business_date', '<=', $upto)
                ->select('loan_receive_allocations.loan_schedule_id AS schedule_id', 'loan_receives.business_date AS d',
                    'loan_receive_allocations.principal AS p', 'loan_receive_allocations.profit AS f', 'loan_receive_allocations.penalty AS pen')
                ->get();
            foreach ($allocs as $alloc) {
                $allocBySchedule[$alloc->schedule_id][] = [
                    'date' => $alloc->d instanceof \DateTimeInterface ? $alloc->d->format('Y-m-d') : (string) $alloc->d,
                    'principal_profit' => $this->bd($alloc->p)->plus((string) $alloc->f),
                    'penalty' => $this->bd($alloc->pen),
                ];
            }
        }

        $schedules = $loanIds === [] ? collect() : LoanSchedule::whereIn('loan_account_id', $loanIds)
            ->whereNull('superseded_by')->get();
        $penaltyCharge = $loanIds === [] ? [] : DuePenaltySetup::whereIn('loan_account_id', $loanIds)
            ->pluck('penal_charge', 'loan_account_id')->all();

        $history = ['loans' => [], 'due' => [], 'outstanding' => [], 'settled' => []];
        foreach ($loans as $loan) {
            $history['loans'][$loan->id] = [
                'disbursed_on' => $loan->disbursed_at->toDateString(),
                'payable' => $this->bd($payable[$loan->id] ?? 0),
                'receives' => $receives[$loan->id] ?? [],
            ];
        }

        // Per-date aggregates walk every business day the caller may
        // ask about: each loan's disbursement day through $upto.
        $dates = BusinessDay::where('branch_id', $branchId)
            ->where('business_date', '<=', $upto)->orderBy('business_date')
            ->pluck('business_date')->map(fn ($d) => $d->toDateString())->all();
        $schedulesByLoan = $schedules->groupBy('loan_account_id');
        foreach ($dates as $date) {
            $dueTotal = BigDecimal::zero();
            $outstandingTotal = BigDecimal::zero();
            foreach ($loans as $loan) {
                if ($loan->disbursed_at->toDateString() > $date) {
                    continue;
                }
                // Outstanding at END of $date.
                $receivedUpto = $this->receivedUpto($history['loans'][$loan->id], $date);
                $remaining = $history['loans'][$loan->id]['payable']->minus($receivedUpto);
                if ($remaining->isPositive()) {
                    $outstandingTotal = $outstandingTotal->plus($remaining);
                }
                // Due at START of $date: schedule rows due on/before
                // $date, unpaid (principal+profit) before $date, plus
                // the penalty charge on rows overdue before $date.
                $charge = $this->bd($penaltyCharge[$loan->id] ?? 0);
                foreach ($schedulesByLoan->get($loan->id, collect()) as $row) {
                    if ($row->due_date->toDateString() > $date) {
                        continue;
                    }
                    $paidPP = BigDecimal::zero();
                    $paidPen = BigDecimal::zero();
                    foreach ($allocBySchedule[$row->id] ?? [] as $alloc) {
                        if ($alloc['date'] < $date) {
                            $paidPP = $paidPP->plus($alloc['principal_profit']);
                            $paidPen = $paidPen->plus($alloc['penalty']);
                        }
                    }
                    $unpaid = $this->bd($row->total_due)->minus($paidPP);
                    if ($unpaid->isPositive()) {
                        $dueTotal = $dueTotal->plus($unpaid);
                        if ($charge->isPositive() && $row->due_date->toDateString() < $date) {
                            $pen = $charge->minus($paidPen);
                            if ($pen->isPositive()) {
                                $dueTotal = $dueTotal->plus($pen);
                            }
                        }
                    }
                }
                // Settled on $date: cumulative principal+profit
                // reaches the payable on $date, not before it.
                $payableBd = $history['loans'][$loan->id]['payable'];
                if ($payableBd->isPositive()) {
                    $before = $this->receivedBefore($history['loans'][$loan->id], $date);
                    if ($before->isLessThan($payableBd) && ! $receivedUpto->isLessThan($payableBd)) {
                        $dayTotal = BigDecimal::zero();
                        foreach ($history['loans'][$loan->id]['receives'] as $receive) {
                            if ($receive['date'] === $date) {
                                $dayTotal = $dayTotal->plus($receive['total']);
                            }
                        }
                        $cur = $history['settled'][$date] ?? ['count' => 0, 'total' => '0.00'];
                        $history['settled'][$date] = [
                            'count' => $cur['count'] + 1,
                            'total' => $this->money($this->bd($cur['total'])->plus($dayTotal)),
                        ];
                    }
                }
            }
            $history['due'][$date] = $this->money($dueTotal);
            $history['outstanding'][$date] = $this->money($outstandingTotal);
        }

        return $history;
    }

    /** Σ principal+profit received strictly before $date. */
    private function receivedBefore(array $loan, string $date): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($loan['receives'] as $receive) {
            if ($receive['date'] < $date) {
                $sum = $sum->plus($receive['principal_profit']);
            }
        }

        return $sum;
    }

    /** Σ principal+profit received on/before $date. */
    private function receivedUpto(array $loan, string $date): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($loan['receives'] as $receive) {
            if ($receive['date'] <= $date) {
                $sum = $sum->plus($receive['principal_profit']);
            }
        }

        return $sum;
    }

    // =====================================================================
    // Row helpers
    // =====================================================================

    private function zeroRow(string $date): array
    {
        return [
            'date' => $date,
            'savings_due' => '0.00', 'savings_collected' => '0.00',
            'admission_count' => 0, 'admission_amount' => '0.00',
            'insurance_count' => 0, 'insurance_amount' => '0.00',
            'withdrawal_count' => 0, 'withdrawal_amount' => '0.00',
            'member_exit_count' => 0, 'other_fees' => '0.00', 'total_in' => '0.00',
            'disbursed_count' => 0, 'disbursed_amount' => '0.00',
            'settled_count' => 0, 'settled_amount' => '0.00',
            'installment_due' => '0.00', 'installment_collected' => '0.00',
            'total_loan' => '0.00', 'grand_total' => '0.00',
            'loan_outstanding' => '0.00', 'other_expenses' => '0.00', 'cash_in_hand' => '0.00',
        ];
    }

    /** Sum flow columns; balance columns take the LAST row's value. */
    private function totalRow(array $rows): array
    {
        $total = $this->zeroRow('');
        $sumKeys = ['savings_due', 'savings_collected', 'admission_amount', 'insurance_amount',
            'withdrawal_amount', 'other_fees', 'total_in', 'disbursed_amount', 'settled_amount',
            'installment_due', 'installment_collected', 'total_loan', 'grand_total', 'other_expenses'];
        $countKeys = ['admission_count', 'insurance_count', 'withdrawal_count', 'member_exit_count',
            'disbursed_count', 'settled_count'];
        foreach ($rows as $row) {
            foreach ($sumKeys as $key) {
                $total[$key] = $this->money($this->bd($total[$key])->plus((string) $row[$key]));
            }
            foreach ($countKeys as $key) {
                $total[$key] += (int) $row[$key];
            }
        }
        if ($rows !== []) {
            $lastRow = $rows[count($rows) - 1];
            $total['loan_outstanding'] = $lastRow['loan_outstanding'];
            $total['cash_in_hand'] = $lastRow['cash_in_hand'];
        }

        return $total;
    }

    /** @param string[] $moneyKeys @param string[] $countKeys */
    private function sumRows(array $rows, array $moneyKeys, array $countKeys): array
    {
        $total = [];
        foreach ($moneyKeys as $key) {
            $sum = BigDecimal::zero();
            foreach ($rows as $row) {
                $sum = $sum->plus((string) $row[$key]);
            }
            $total[$key] = $this->money($sum);
        }
        foreach ($countKeys as $key) {
            $total[$key] = collect($rows)->sum(fn ($row) => (int) $row[$key]);
        }

        return $total;
    }

    /** Date-bucketed SUM over a query, keyed by Y-m-d. */
    private function sumByDate($query, string $dateColumn, string $amountColumn): array
    {
        return $query->selectRaw($dateColumn.' AS d, COALESCE(SUM('.$amountColumn.'), 0) AS total')
            ->groupBy($dateColumn)->get()
            ->mapWithKeys(fn ($r) => [
                ($r->d instanceof \DateTimeInterface ? $r->d->format('Y-m-d') : (string) $r->d) => $this->money($r->total),
            ])->all();
    }
}
