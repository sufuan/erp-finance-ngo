<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\DuePenaltySetup;
use App\Models\LoanAccount;
use App\Models\LoanReceive;
use App\Models\LoanReceiveAllocation;
use App\Models\LoanSchedule;
use App\Models\User;
use App\Models\Voucher;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 18 — loan money: disbursement and installment receives.
 *
 * Receive allocation follows `settings.loan.allocation_order`
 * (penalty → profit → principal), FIFO across schedule rows within each
 * bucket. Paying ahead applies to future rows — that IS the advance
 * balance, auto-applied when those rows fall due. Over-payment beyond
 * the total outstanding closes the loan and refunds the excess by a
 * payment voucher (when `loan.allow_overpayment` is off, allocation is
 * capped at rows already due and any excess is rejected instead).
 * Penalty due = the loan's DuePenaltySetup charge per overdue unpaid
 * installment, less penalty already paid on that row.
 *
 * Transfer-from-deposit posts TWO balanced vouchers: the deposit
 * payment (Step 11 service) and the loan receive — the actor's cash
 * ledger nets to zero. Cancellation reverses both.
 */
class LoanMoneyService
{
    public function __construct(
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private SettingsService $settings,
        private DepositTransactionService $depositTransactions,
    ) {}

    public function disburse(LoanAccount $loan, User $actor): LoanAccount
    {
        return DB::transaction(function () use ($loan, $actor) {
            $loan = LoanAccount::lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'Opened') {
                throw ValidationException::withMessages(['status' => __('loan_not_opened')]);
            }
            $this->posting->post([
                'branch_id' => $loan->branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                'transaction_date' => $this->businessDays->currentDate($loan->branch_id),
                'comments' => 'Loan disbursement '.$loan->loan_number,
                'lines' => [['mapping_key' => 'loan.disbursement', 'amount' => (string) $loan->loan_amount, 'loan_account_id' => $loan->id]],
                'movements' => [
                    ['account_type' => 'loan', 'account_id' => $loan->id, 'tran_code' => 'DISBURSE', 'amount' => (string) $loan->loan_amount, 'ref_type' => 'loan_account', 'ref_id' => $loan->id],
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_OUT', 'amount' => '-'.(string) $loan->loan_amount],
                ],
            ]);
            $loan->update(['status' => 'Disbursed', 'disbursed_by' => $actor->id, 'disbursed_at' => now()]);

            return $loan->fresh();
        });
    }

    /**
     * Penalty currently due = per-row setup charge (overdue rows, net of
     * paid) + Step 19 additional penalties − Step 19 charge waivers −
     * penalty already collected beyond the setup caps.
     */
    public function penaltyDue(LoanAccount $loan, ?string $asOf = null): string
    {
        $asOf ??= $this->businessDays->currentDate($loan->branch_id);
        $charge = BigDecimal::of((string) (DuePenaltySetup::where('loan_account_id', $loan->id)->value('penal_charge') ?? 0));
        $due = BigDecimal::zero();
        $beyond = BigDecimal::zero();
        foreach ($loan->schedules()->get() as $row) {
            $paidPenalty = BigDecimal::of((string) $row->paid_penalty);
            if ($paidPenalty->isGreaterThan($charge)) {
                $beyond = $beyond->plus($paidPenalty->minus($charge));
            }
            if ($row->due_date->toDateString() < $asOf && $row->status !== 'Paid') {
                $rowDue = $charge->minus($paidPenalty);
                if ($rowDue->isPositive()) {
                    $due = $due->plus($rowDue);
                }
            }
        }
        $extra = BigDecimal::of((string) \App\Models\LoanPenalty::where('loan_account_id', $loan->id)->whereNull('cancel_at')->sum('penalty_amount'));
        $waived = BigDecimal::of((string) \App\Models\LoanWaiver::where('loan_account_id', $loan->id)->whereNull('cancel_at')->sum('charge_waive'));
        $total = $due->plus($extra)->minus($waived)->minus($beyond);
        if ($total->isNegative()) {
            $total = BigDecimal::zero();
        }

        return (string) $total->toScale(2, RoundingMode::HalfUp);
    }

    /** @return array{principal: string, profit: string, penalty: string, total: string} */
    public function outstanding(LoanAccount $loan): array
    {
        $rows = $loan->schedules()->get();
        $principal = $rows->reduce(fn ($s, $r) => $s->plus(BigDecimal::of((string) $r->principal_due)->minus((string) $r->paid_principal)), BigDecimal::zero());
        $profit = $rows->reduce(fn ($s, $r) => $s->plus(BigDecimal::of((string) $r->profit_due)->minus((string) $r->paid_profit)), BigDecimal::zero());
        $penalty = BigDecimal::of($this->penaltyDue($loan));

        return [
            'principal' => (string) $principal->toScale(2, RoundingMode::HalfUp),
            'profit' => (string) $profit->toScale(2, RoundingMode::HalfUp),
            'penalty' => (string) $penalty->toScale(2, RoundingMode::HalfUp),
            'total' => (string) $principal->plus($profit)->plus($penalty)->toScale(2, RoundingMode::HalfUp),
        ];
    }

    /** Amount due today (rows due on/before the business date + penalty). */
    public function todaysDue(LoanAccount $loan): string
    {
        $today = $this->businessDays->currentDate($loan->branch_id);
        $due = BigDecimal::of($this->penaltyDue($loan, $today));
        foreach ($loan->schedules()->where('due_date', '<=', $today)->where('status', '!=', 'Paid')->get() as $row) {
            $due = $due->plus(BigDecimal::of((string) $row->total_due)
                ->minus((string) $row->paid_principal)->minus((string) $row->paid_profit));
        }

        return (string) $due->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * @param  array{amount: string, receive_date?: ?string, document_number?: ?string, comments?: ?string, transfer_from_deposit?: bool, deposit_account_id?: ?int, penalty_charge?: ?string, manual?: ?array{investment: string, profit: string, penalty: string}}  $input
     */
    public function receive(LoanAccount $loan, array $input, User $actor): LoanReceive
    {
        return DB::transaction(function () use ($loan, $input, $actor) {
            $loan = LoanAccount::with('schedules')->lockForUpdate()->findOrFail($loan->id);
            if (! in_array($loan->status, ['Disbursed', 'Active'], true)) {
                throw ValidationException::withMessages(['loan_account_id' => __('loan_not_disbursed')]);
            }
            $amount = BigDecimal::of((string) $input['amount']);
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => __('amount_must_be_positive')]);
            }
            $businessDate = $this->businessDays->currentDate($loan->branch_id);
            $transfer = (bool) ($input['transfer_from_deposit'] ?? false);

            $depositVoucherId = null;
            if ($transfer) {
                $depositAccount = DepositAccount::findOrFail($input['deposit_account_id']);
                $outstanding = $this->outstanding($loan);
                if ($amount->isGreaterThan($outstanding['total'])) {
                    throw ValidationException::withMessages(['amount' => __('transfer_exceeds_outstanding')]);
                }
                $detail = $this->depositTransactions->payment(
                    $depositAccount, (string) $input['amount'],
                    $input['document_number'] ?? null, $input['comments'] ?? null, $actor,
                );
                $depositVoucherId = $detail->voucher_id;
            }

            [$allocations, $applied] = $this->allocate($loan, $amount, $input, $businessDate);
            $appliedTotal = BigDecimal::of($applied['penalty'])->plus($applied['profit'])->plus($applied['principal']);
            $excess = $amount->minus($appliedTotal);

            $receive = LoanReceive::create([
                'loan_account_id' => $loan->id, 'member_id' => $loan->member_id,
                'receive_date' => $input['receive_date'] ?? $businessDate,
                'business_date' => $businessDate,
                'installment_amount' => (string) $amount,
                'investment_amount' => $applied['principal'], 'profit_amount' => $applied['profit'],
                'penalty_charge' => $applied['penalty'],
                'transfer_from_deposit' => $transfer,
                'deposit_account_id' => $transfer ? $input['deposit_account_id'] : null,
                'document_number' => $input['document_number'] ?? null,
                'comments' => $input['comments'] ?? null,
                'deposit_voucher_id' => $depositVoucherId,
                'entry_by' => $actor->id, 'entry_at' => now(),
            ]);

            $lines = [];
            if (BigDecimal::of($applied['principal'])->isPositive()) {
                $lines[] = ['mapping_key' => 'loan.receive', 'amount' => $applied['principal'], 'loan_account_id' => $loan->id];
            }
            if (BigDecimal::of($applied['profit'])->isPositive()) {
                $lines[] = ['mapping_key' => 'loan.receive.profit', 'amount' => $applied['profit'], 'loan_account_id' => $loan->id];
            }
            if (BigDecimal::of($applied['penalty'])->isPositive()) {
                $lines[] = ['mapping_key' => 'loan.penalty', 'amount' => $applied['penalty'], 'loan_account_id' => $loan->id];
            }
            if ($excess->isPositive()) {
                // The excess is held as a payable (2060) inside the same
                // receipt voucher, then refunded below — cash in = the
                // full amount the member handed over.
                $lines[] = ['mapping_key' => 'loan.advance', 'amount' => (string) $excess, 'loan_account_id' => $loan->id];
            }
            $voucher = null;
            if ($lines !== []) {
                $voucher = $this->posting->post([
                    'branch_id' => $loan->branch_id, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
                    'transaction_date' => $businessDate,
                    'comments' => $input['comments'] ?? null,
                    'lines' => $lines,
                    'movements' => [
                        ['account_type' => 'loan', 'account_id' => $loan->id, 'tran_code' => 'RECEIVE', 'amount' => '-'.$applied['principal'], 'ref_type' => 'loan_receive', 'ref_id' => $receive->id],
                        ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => (string) $amount],
                    ],
                ]);
                $receive->update(['voucher_id' => $voucher->id]);
            }

            foreach ($allocations as $scheduleId => $split) {
                $row = LoanSchedule::lockForUpdate()->findOrFail($scheduleId);
                $row->paid_principal = (string) BigDecimal::of((string) $row->paid_principal)->plus($split['principal']);
                $row->paid_profit = (string) BigDecimal::of((string) $row->paid_profit)->plus($split['profit']);
                $row->paid_penalty = (string) BigDecimal::of((string) $row->paid_penalty)->plus($split['penalty']);
                $row->status = $this->rowStatus($row);
                $row->save();
                LoanReceiveAllocation::create([
                    'loan_receive_id' => $receive->id, 'loan_schedule_id' => $scheduleId,
                    'principal' => $split['principal'], 'profit' => $split['profit'], 'penalty' => $split['penalty'],
                ]);
            }

            // Excess over the total outstanding: refund in cash against
            // the advance payable; the loan closes.
            if ($excess->isPositive()) {
                $this->posting->post([
                    'branch_id' => $loan->branch_id, 'voucher_type' => 'Payment', 'entry_by' => $actor->id,
                    'transaction_date' => $businessDate,
                    'comments' => 'Advance refund on loan closure '.$loan->loan_number,
                    'lines' => [['mapping_key' => 'loan.advance.refund', 'amount' => (string) $excess, 'loan_account_id' => $loan->id]],
                    'movements' => [
                        ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_OUT', 'amount' => '-'.(string) $excess],
                    ],
                ]);
            }

            $allPaid = $loan->schedules()->where('status', '!=', 'Paid')->doesntExist();
            $loan->update(['status' => $allPaid ? 'Closed' : 'Active']);

            return $receive->fresh();
        });
    }

    /** Only the LATEST receipt per loan is cancellable (binding rule). */
    public function cancelLatest(LoanReceive $receive, User $by, string $reason): void
    {
        DB::transaction(function () use ($receive, $by, $reason) {
            $receive = LoanReceive::with('allocations')->lockForUpdate()->findOrFail($receive->id);
            if ($receive->cancel_at !== null) {
                throw ValidationException::withMessages(['receive' => __('receive_already_cancelled')]);
            }
            $latestId = LoanReceive::where('loan_account_id', $receive->loan_account_id)
                ->whereNull('cancel_at')->max('id');
            if ((int) $latestId !== (int) $receive->id) {
                throw ValidationException::withMessages(['receive' => __('only_latest_receipt_cancellable')]);
            }
            if ($receive->voucher_id) {
                $this->posting->cancel(Voucher::findOrFail($receive->voucher_id), $by, $reason);
            }
            if ($receive->deposit_voucher_id) {
                // Reverse the funding deposit payment at voucher level and
                // resync the deposit cache (system-initiated reversal).
                $this->posting->cancel(Voucher::findOrFail($receive->deposit_voucher_id), $by, $reason);
                $detail = \App\Models\DepositTransaction::where('voucher_id', $receive->deposit_voucher_id)->first();
                if ($detail) {
                    $detail->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
                    $this->depositTransactions->resyncCache($detail->account);
                }
            }
            foreach ($receive->allocations as $allocation) {
                $row = LoanSchedule::lockForUpdate()->findOrFail($allocation->loan_schedule_id);
                $row->paid_principal = (string) BigDecimal::of((string) $row->paid_principal)->minus((string) $allocation->principal);
                $row->paid_profit = (string) BigDecimal::of((string) $row->paid_profit)->minus((string) $allocation->profit);
                $row->paid_penalty = (string) BigDecimal::of((string) $row->paid_penalty)->minus((string) $allocation->penalty);
                $row->status = $this->rowStatus($row);
                $row->save();
            }
            $receive->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
            $loan = LoanAccount::findOrFail($receive->loan_account_id);
            $remaining = LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->exists();
            if ($loan->status !== 'WrittenOff') {
                $loan->update(['status' => $remaining ? 'Active' : 'Disbursed']);
            }
        });
    }

    private function rowStatus(LoanSchedule $row): string
    {
        $paid = BigDecimal::of((string) $row->paid_principal)->plus((string) $row->paid_profit);
        if ($paid->isZero()) {
            return 'Pending';
        }

        return $paid->isGreaterThanOrEqualTo(BigDecimal::of((string) $row->total_due)) ? 'Paid' : 'Partial';
    }

    /**
     * Split an amount into per-row allocations following the configured
     * order. Returns [allocationsByScheduleId, appliedTotals].
     */
    private function allocate(LoanAccount $loan, BigDecimal $amount, array $input, string $businessDate): array
    {
        $order = $this->settings->get('loan.allocation_order', ['penalty', 'profit', 'principal']);
        if (is_string($order)) {
            $order = json_decode($order, true) ?: ['penalty', 'profit', 'principal'];
        }
        $allowOver = filter_var($this->settings->get('loan.allow_overpayment', true), FILTER_VALIDATE_BOOLEAN);
        $rows = $loan->schedules()->orderBy('installment_no')->get();
        $charge = BigDecimal::of((string) (DuePenaltySetup::where('loan_account_id', $loan->id)->value('penal_charge') ?? 0));

        // Bucket caps per row.
        $caps = [];
        foreach ($rows as $row) {
            $isOverdue = $row->due_date->toDateString() < $businessDate && $row->status !== 'Paid';
            $penaltyCap = $charge->minus((string) $row->paid_penalty);
            $caps[$row->id] = [
                'penalty' => $isOverdue && $penaltyCap->isPositive() ? $penaltyCap : BigDecimal::zero(),
                'profit' => BigDecimal::of((string) $row->profit_due)->minus((string) $row->paid_profit),
                'principal' => BigDecimal::of((string) $row->principal_due)->minus((string) $row->paid_principal),
            ];
            if (! $allowOver && $row->due_date->toDateString() > $businessDate) {
                $caps[$row->id]['profit'] = BigDecimal::zero();
                $caps[$row->id]['principal'] = BigDecimal::zero();
            }
        }

        $applied = ['penalty' => BigDecimal::zero(), 'profit' => BigDecimal::zero(), 'principal' => BigDecimal::zero()];
        $allocations = [];
        $addSlice = function (int $rowId, string $bucket, BigDecimal $slice) use (&$allocations, &$applied, &$caps) {
            $allocations[$rowId] ??= ['principal' => BigDecimal::zero(), 'profit' => BigDecimal::zero(), 'penalty' => BigDecimal::zero()];
            $allocations[$rowId][$bucket] = $allocations[$rowId][$bucket]->plus($slice);
            $caps[$rowId][$bucket] = $caps[$rowId][$bucket]->minus($slice);
            $applied[$bucket] = $applied[$bucket]->plus($slice);
        };

        if (! empty($input['manual'])) {
            // Manual mode: the officer's explicit bucket totals, applied
            // FIFO across rows within each bucket (configured order).
            $targets = [
                'penalty' => BigDecimal::of((string) $input['manual']['penalty']),
                'profit' => BigDecimal::of((string) $input['manual']['profit']),
                'principal' => BigDecimal::of((string) $input['manual']['investment']),
            ];
            $sum = $targets['penalty']->plus($targets['profit'])->plus($targets['principal']);
            if (! $sum->isEqualTo($amount)) {
                throw ValidationException::withMessages(['amount' => __('split_must_equal_amount')]);
            }
            foreach ($order as $bucket) {
                $bucketCap = collect($caps)->reduce(fn ($s, $c) => $s->plus($c[$bucket]), BigDecimal::zero());
                if ($bucket === 'penalty') {
                    // The Step 19 pool is collectable beyond per-row caps.
                    $bucketCap = BigDecimal::of($this->penaltyDue($loan, $businessDate));
                }
                if ($targets[$bucket]->isGreaterThan($bucketCap)) {
                    throw ValidationException::withMessages(['amount' => __('split_exceeds_due')]);
                }
                $left = $targets[$bucket];
                foreach ($rows as $row) {
                    if ($left->isZero()) {
                        break;
                    }
                    $cap = $caps[$row->id][$bucket];
                    if (! $cap->isPositive()) {
                        continue;
                    }
                    $slice = $cap->isLessThan($left) ? $cap : $left;
                    $addSlice($row->id, $bucket, $slice);
                    $left = $left->minus($slice);
                }
                if ($bucket === 'penalty' && $left->isPositive()) {
                    $anchorId = $rows->firstWhere('status', '!=', 'Paid')?->id ?? $rows->first()?->id;
                    if ($anchorId !== null) {
                        $addSlice($anchorId, 'penalty', $left);
                    }
                }
            }
        } else {
            // Auto mode, two phases. Phase 1: penalty is a receipt-level
            // charge — collect it across overdue rows FIFO first (up to
            // the officer's penalty_charge when one was entered). Phase 2:
            // walk installments FIFO; within a row apply the remaining
            // buckets in their configured relative order, so an
            // installment completes before the next one starts.
            $remaining = $amount;
            if (in_array('penalty', $order, true)) {
                // Total penalty collectable now (setup + Step 19 pools).
                $penaltyLeft = BigDecimal::of($this->penaltyDue($loan, $businessDate));
                if (isset($input['penalty_charge'])) {
                    $officerCap = BigDecimal::of((string) $input['penalty_charge']);
                    if ($officerCap->isLessThan($penaltyLeft)) {
                        $penaltyLeft = $officerCap;
                    }
                }
                if ($penaltyLeft->isGreaterThan($remaining)) {
                    $penaltyLeft = $remaining;
                }
                $anchorId = null;
                foreach ($rows as $row) {
                    if (! $penaltyLeft->isPositive()) {
                        break;
                    }
                    $cap = $caps[$row->id]['penalty'];
                    if (! $cap->isPositive()) {
                        continue;
                    }
                    $slice = $cap->isLessThan($penaltyLeft) ? $cap : $penaltyLeft;
                    $addSlice($row->id, 'penalty', $slice);
                    $remaining = $remaining->minus($slice);
                    $penaltyLeft = $penaltyLeft->minus($slice);
                    $anchorId = $row->id;
                }
                if ($penaltyLeft->isPositive()) {
                    // Pool penalty (Step 19 penalties net of waivers) has
                    // no per-row cap: anchor it on an unpaid row so the
                    // paid_penalty trail stays exact.
                    $anchorId ??= $rows->firstWhere('status', '!=', 'Paid')?->id ?? $rows->first()?->id;
                    if ($anchorId !== null) {
                        $addSlice($anchorId, 'penalty', $penaltyLeft);
                        $remaining = $remaining->minus($penaltyLeft);
                    }
                }
            }
            $restOrder = array_values(array_filter($order, fn ($b) => $b !== 'penalty'));
            foreach ($rows as $row) {
                if ($remaining->isZero()) {
                    break;
                }
                foreach ($restOrder as $bucket) {
                    if ($remaining->isZero()) {
                        break;
                    }
                    $cap = $caps[$row->id][$bucket];
                    if (! $cap->isPositive()) {
                        continue;
                    }
                    $slice = $cap->isLessThan($remaining) ? $cap : $remaining;
                    $addSlice($row->id, $bucket, $slice);
                    $remaining = $remaining->minus($slice);
                }
            }
        }

        $stringify = fn (BigDecimal $v) => (string) $v->toScale(2, RoundingMode::HalfUp);
        $allocations = collect($allocations)->map(fn ($split) => [
            'principal' => $stringify($split['principal']),
            'profit' => $stringify($split['profit']),
            'penalty' => $stringify($split['penalty']),
        ])->all();

        return [$allocations, [
            'principal' => $stringify($applied['principal']),
            'profit' => $stringify($applied['profit']),
            'penalty' => $stringify($applied['penalty']),
        ]];
    }
}
