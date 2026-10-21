<?php

namespace App\Services;

use App\Models\GlMapping;
use App\Models\InsuranceClaim;
use App\Models\LoanAccount;
use App\Models\LoanFee;
use App\Models\LoanPenalty;
use App\Models\LoanReschedule;
use App\Models\LoanSchedule;
use App\Models\LoanWaiver;
use App\Models\User;
use App\Models\Writeoff;
use App\Models\WriteoffCollection;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 19 — loan adjustments with full audit: reschedule (new schedule
 * version, old rows superseded), waiver (exact split reduction, exact
 * cancel-restore via row_splits), additional penalty (pool + optional
 * additional profit), write-off (against the LLP provision via the
 * loan.writeoff mapping) and post-write-off recovery, insurance claims.
 */
class LoanAdjustmentService
{
    public function __construct(
        private ScheduleEngine $schedule,
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private LoanMoneyService $money,
    ) {}

    private function money2(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }

    private function recomputeLoanTotals(LoanAccount $loan): void
    {
        $rows = $loan->schedules()->get();
        $profit = $rows->reduce(fn ($s, $r) => $s->plus((string) $r->profit_due), BigDecimal::zero());
        $total = $rows->reduce(fn ($s, $r) => $s->plus((string) $r->total_due), BigDecimal::zero());
        $loan->update([
            'profit_amount' => $this->money2($profit),
            'total_repayment' => $this->money2($total),
        ]);
    }

    // ---- Reschedule ----

    /**
     * @param  array{reschedule_date: string, additional_profit: string, frequency: string, no_of_installments: int, first_installment_date: string, fees?: array<string, string>}  $input
     */
    public function reschedule(LoanAccount $loan, array $input, User $actor): LoanReschedule
    {
        return DB::transaction(function () use ($loan, $input, $actor) {
            $loan = LoanAccount::lockForUpdate()->findOrFail($loan->id);
            if (! in_array($loan->status, ['Disbursed', 'Active'], true)) {
                throw ValidationException::withMessages(['loan_account_id' => __('loan_not_active')]);
            }
            $outstanding = $this->money->outstanding($loan);
            $newProfit = BigDecimal::of($outstanding['profit'])->plus((string) $input['additional_profit']);
            $samity = $loan->member?->samity;
            $startNo = (int) $loan->schedules()->max('installment_no') + 1;
            $rows = $this->schedule->respreadRows(
                $outstanding['principal'], (string) $newProfit,
                $input['frequency'], (int) $input['no_of_installments'],
                $input['first_installment_date'], $startNo,
                $loan->branch_id, $samity?->id, $samity?->samity_day,
            );
            $installment = collect($rows)->first();
            $reschedule = LoanReschedule::create([
                'loan_account_id' => $loan->id, 'reschedule_date' => $input['reschedule_date'],
                'additional_profit' => $input['additional_profit'], 'frequency' => $input['frequency'],
                'installment_amount' => $rows[0]['total_due'],
                'no_of_installments' => (int) $input['no_of_installments'],
                'first_installment_date' => $input['first_installment_date'],
                'installment_expire_date' => end($rows)['due_date'],
                'created_by' => $actor->id,
            ]);
            // Supersede unpaid rows: trim dues to what was actually paid
            // (settled by construction) and keep them as history.
            foreach ($loan->schedules()->where('status', '!=', 'Paid')->get() as $old) {
                $old->update([
                    'principal_due' => $old->paid_principal, 'profit_due' => $old->paid_profit,
                    'total_due' => (string) BigDecimal::of((string) $old->paid_principal)->plus((string) $old->paid_profit),
                    'status' => 'Paid', 'superseded_by' => $reschedule->id,
                ]);
            }
            foreach ($rows as $row) {
                LoanSchedule::create(['loan_account_id' => $loan->id, ...$row]);
            }
            $loan->update([
                'frequency' => $input['frequency'],
                'no_of_installments' => (int) $input['no_of_installments'],
                'installment_amount' => $reschedule->installment_amount,
                'actual_installment_amount' => $reschedule->installment_amount,
                'last_installment_amount' => end($rows)['total_due'],
                'installment_from_date' => $input['first_installment_date'],
                'installment_expire_date' => $reschedule->installment_expire_date,
            ]);
            $this->postFees($loan, $input['fees'] ?? [], $actor);
            $this->recomputeLoanTotals($loan->fresh());

            return $reschedule;
        });
    }

    /** Reschedule/opening-style fees: one loan_fees row each + one Receipt voucher. */
    private function postFees(LoanAccount $loan, array $fees, User $actor): void
    {
        $rows = [];
        $lines = [];
        $total = BigDecimal::zero();
        foreach (LoanFee::TYPES as $type) {
            $amount = (string) ($fees[$type] ?? '0');
            if (! BigDecimal::of($amount)->isPositive()) {
                continue;
            }
            $mapping = GlMapping::byKey('loan.fee.'.$type);
            $rows[] = LoanFee::create([
                'loan_account_id' => $loan->id, 'fee_type' => $type,
                'amount' => $amount, 'ledger_id' => $mapping->credit_ledger_id,
            ]);
            $lines[] = ['mapping_key' => 'loan.fee.'.$type, 'amount' => $amount, 'loan_account_id' => $loan->id];
            $total = $total->plus($amount);
        }
        if ($lines !== []) {
            $voucher = $this->posting->post([
                'branch_id' => $loan->branch_id, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
                'transaction_date' => $this->businessDays->currentDate($loan->branch_id),
                'comments' => 'Loan fees for '.$loan->loan_number,
                'lines' => $lines,
                'movements' => [
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => (string) $total],
                ],
            ]);
            foreach ($rows as $row) {
                $row->update(['voucher_id' => $voucher->id]);
            }
        }
    }

    // ---- Waiver ----

    /**
     * @param  array{waiver_date: string, principal_waive: string, profit_waive: string, charge_waive: string, comments?: ?string}  $input
     */
    public function waive(LoanAccount $loan, array $input, User $actor): LoanWaiver
    {
        return DB::transaction(function () use ($loan, $input, $actor) {
            $loan = LoanAccount::lockForUpdate()->findOrFail($loan->id);
            $outstanding = $this->money->outstanding($loan);
            foreach (['principal' => 'principal_waive', 'profit' => 'profit_waive', 'penalty' => 'charge_waive'] as $bucket => $field) {
                if (BigDecimal::of((string) $input[$field])->isGreaterThan($outstanding[$bucket])) {
                    throw ValidationException::withMessages([$field => __('waive_exceeds_due')]);
                }
            }
            $splits = [];
            $reduce = function (string $bucket, BigDecimal $amount) use ($loan, &$splits) {
                $column = $bucket === 'principal' ? 'principal_due' : 'profit_due';
                $paidColumn = $bucket === 'principal' ? 'paid_principal' : 'paid_profit';
                $left = $amount;
                foreach ($loan->schedules()->whereNull('superseded_by')->orderBy('installment_no')->get() as $row) {
                    if ($left->isZero()) {
                        break;
                    }
                    $reducible = BigDecimal::of((string) $row->{$column})->minus((string) $row->{$paidColumn});
                    if (! $reducible->isPositive()) {
                        continue;
                    }
                    $slice = $reducible->isLessThan($left) ? $reducible : $left;
                    $row->{$column} = (string) BigDecimal::of((string) $row->{$column})->minus($slice);
                    $row->total_due = (string) BigDecimal::of((string) $row->principal_due)->plus((string) $row->profit_due);
                    $row->status = $this->rowStatus($row);
                    $row->save();
                    $splits[] = ['schedule_id' => $row->id, $bucket => (string) $slice];
                    $left = $left->minus($slice);
                }
            };
            $reduce('principal', BigDecimal::of((string) $input['principal_waive']));
            $reduce('profit', BigDecimal::of((string) $input['profit_waive']));

            $total = BigDecimal::of((string) $input['principal_waive'])
                ->plus((string) $input['profit_waive'])->plus((string) $input['charge_waive']);
            $waiver = LoanWaiver::create([
                'loan_account_id' => $loan->id, 'waiver_date' => $input['waiver_date'],
                'principal_waive' => $input['principal_waive'], 'profit_waive' => $input['profit_waive'],
                'charge_waive' => $input['charge_waive'], 'total_waiver' => (string) $total,
                'row_splits' => $splits, 'comments' => $input['comments'] ?? null,
                'entry_by' => $actor->id, 'entry_at' => now(),
            ]);
            $this->recomputeLoanTotals($loan->fresh());
            $after = $this->money->outstanding($loan->fresh());
            if ($after['total'] === '0.00' && in_array($loan->status, ['Disbursed', 'Active'], true)) {
                $loan->update(['status' => 'Closed']);
            }

            return $waiver;
        });
    }

    public function cancelWaiver(LoanWaiver $waiver, User $by): void
    {
        DB::transaction(function () use ($waiver, $by) {
            $waiver = LoanWaiver::lockForUpdate()->findOrFail($waiver->id);
            if ($waiver->cancel_at !== null) {
                throw ValidationException::withMessages(['waiver' => __('already_cancelled')]);
            }
            foreach (($waiver->row_splits ?? []) as $split) {
                $row = LoanSchedule::lockForUpdate()->findOrFail($split['schedule_id']);
                if (isset($split['principal'])) {
                    $row->principal_due = (string) BigDecimal::of((string) $row->principal_due)->plus($split['principal']);
                }
                if (isset($split['profit'])) {
                    $row->profit_due = (string) BigDecimal::of((string) $row->profit_due)->plus($split['profit']);
                }
                $row->total_due = (string) BigDecimal::of((string) $row->principal_due)->plus((string) $row->profit_due);
                $row->status = $this->rowStatus($row);
                $row->save();
            }
            $waiver->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
            $loan = LoanAccount::findOrFail($waiver->loan_account_id);
            $this->recomputeLoanTotals($loan);
            if ($loan->status === 'Closed') {
                $loan->update(['status' => 'Active']);
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

    // ---- Additional penalty ----

    /**
     * @param  array{entry_date: string, additional_profit: string, penalty_amount: string, comments?: ?string}  $input
     */
    public function addPenalty(LoanAccount $loan, array $input, User $actor): LoanPenalty
    {
        return DB::transaction(function () use ($loan, $input, $actor) {
            $loan = LoanAccount::lockForUpdate()->findOrFail($loan->id);
            $scheduleId = null;
            if (BigDecimal::of((string) $input['additional_profit'])->isPositive()) {
                $row = $loan->schedules()->whereNull('superseded_by')->where('status', '!=', 'Paid')
                    ->orderByDesc('installment_no')->first();
                if ($row) {
                    $scheduleId = $row->id;
                    $row->profit_due = (string) BigDecimal::of((string) $row->profit_due)->plus((string) $input['additional_profit']);
                    $row->total_due = (string) BigDecimal::of((string) $row->principal_due)->plus((string) $row->profit_due);
                    $row->status = $this->rowStatus($row);
                    $row->save();
                }
            }
            $penalty = LoanPenalty::create([
                'loan_account_id' => $loan->id, 'entry_date' => $input['entry_date'],
                'additional_profit' => $input['additional_profit'],
                'penalty_amount' => $input['penalty_amount'],
                'schedule_id' => $scheduleId, 'comments' => $input['comments'] ?? null,
                'entry_by' => $actor->id, 'entry_at' => now(),
            ]);
            $this->recomputeLoanTotals($loan->fresh());

            return $penalty;
        });
    }

    public function cancelPenalty(LoanPenalty $penalty, User $by): void
    {
        DB::transaction(function () use ($penalty, $by) {
            $penalty = LoanPenalty::lockForUpdate()->findOrFail($penalty->id);
            if ($penalty->cancel_at !== null) {
                throw ValidationException::withMessages(['penalty' => __('already_cancelled')]);
            }
            if ($penalty->schedule_id && BigDecimal::of((string) $penalty->additional_profit)->isPositive()) {
                $row = LoanSchedule::lockForUpdate()->findOrFail($penalty->schedule_id);
                $row->profit_due = (string) BigDecimal::of((string) $row->profit_due)->minus((string) $penalty->additional_profit);
                $row->total_due = (string) BigDecimal::of((string) $row->principal_due)->plus((string) $row->profit_due);
                $row->status = $this->rowStatus($row);
                $row->save();
            }
            $penalty->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
            $this->recomputeLoanTotals(LoanAccount::findOrFail($penalty->loan_account_id));
        });
    }

    // ---- Write-off + recovery ----

    public function writeoff(LoanAccount $loan, array $input, User $actor): Writeoff
    {
        return DB::transaction(function () use ($loan, $input, $actor) {
            $loan = LoanAccount::lockForUpdate()->findOrFail($loan->id);
            if ($loan->status === 'WrittenOff') {
                throw ValidationException::withMessages(['status' => __('already_written_off')]);
            }
            $outstanding = $this->money->outstanding($loan);
            $writeoff = Writeoff::create([
                'loan_account_id' => $loan->id, 'writeoff_date' => $input['writeoff_date'],
                'total_amount' => $outstanding['total'], 'principal' => $outstanding['principal'],
                'profit' => $outstanding['profit'], 'charge' => $outstanding['penalty'],
                'comments' => $input['comments'] ?? null,
                'entry_by' => $actor->id, 'entry_at' => now(),
            ]);
            if (BigDecimal::of($outstanding['principal'])->isPositive()) {
                $this->posting->post([
                    'branch_id' => $loan->branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                    'transaction_date' => $this->businessDays->currentDate($loan->branch_id),
                    'comments' => 'Write-off '.$loan->loan_number,
                    'lines' => [['mapping_key' => 'loan.writeoff', 'amount' => $outstanding['principal'], 'loan_account_id' => $loan->id]],
                    'movements' => [
                        ['account_type' => 'loan', 'account_id' => $loan->id, 'tran_code' => 'WRITEOFF', 'amount' => '-'.$outstanding['principal'], 'ref_type' => 'writeoff', 'ref_id' => $writeoff->id],
                    ],
                ]);
            }
            $loan->update(['status' => 'WrittenOff']);

            return $writeoff;
        });
    }

    public function collectWriteoff(LoanAccount $loan, array $input, User $actor): WriteoffCollection
    {
        return DB::transaction(function () use ($loan, $input, $actor) {
            $loan = LoanAccount::lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'WrittenOff') {
                throw ValidationException::withMessages(['status' => __('loan_not_written_off')]);
            }
            $total = BigDecimal::of((string) $input['principal_recovery'])
                ->plus((string) $input['profit_recovery'])->plus((string) $input['charge_recovery']);
            $collection = WriteoffCollection::create([
                'loan_account_id' => $loan->id, 'recovery_date' => $input['recovery_date'],
                'total_recovery' => (string) $total,
                'principal_recovery' => $input['principal_recovery'],
                'profit_recovery' => $input['profit_recovery'],
                'charge_recovery' => $input['charge_recovery'],
                'comments' => $input['comments'] ?? null,
                'entry_by' => $actor->id, 'entry_at' => now(),
            ]);
            $lines = [];
            if (BigDecimal::of((string) $input['principal_recovery'])->isPositive()) {
                $lines[] = ['mapping_key' => 'loan.receive', 'amount' => $input['principal_recovery'], 'loan_account_id' => $loan->id];
            }
            if (BigDecimal::of((string) $input['profit_recovery'])->isPositive()) {
                $lines[] = ['mapping_key' => 'loan.receive.profit', 'amount' => $input['profit_recovery'], 'loan_account_id' => $loan->id];
            }
            if (BigDecimal::of((string) $input['charge_recovery'])->isPositive()) {
                $lines[] = ['mapping_key' => 'loan.penalty', 'amount' => $input['charge_recovery'], 'loan_account_id' => $loan->id];
            }
            if ($lines !== []) {
                $this->posting->post([
                    'branch_id' => $loan->branch_id, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
                    'transaction_date' => $this->businessDays->currentDate($loan->branch_id),
                    'comments' => $input['comments'] ?? null,
                    'lines' => $lines,
                    'movements' => [
                        ['account_type' => 'loan', 'account_id' => $loan->id, 'tran_code' => 'RECOVERY', 'amount' => (string) $input['principal_recovery'], 'ref_type' => 'writeoff_collection', 'ref_id' => $collection->id],
                        ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => (string) $total],
                    ],
                ]);
            }

            return $collection;
        });
    }

    // ---- Insurance claims ----

    public function decideClaim(InsuranceClaim $claim, string $decision, User $actor): InsuranceClaim
    {
        if ($claim->status !== 'Filed') {
            throw ValidationException::withMessages(['status' => __('claim_already_decided')]);
        }
        $claim->update([
            'status' => $decision === 'approve' ? 'Approved' : 'Rejected',
            'decided_by' => $actor->id, 'decided_at' => now(),
        ]);

        return $claim->fresh();
    }

    /**
     * Pay an approved claim: the attached loan (if any) is settled from
     * the claim up to its outstanding via a normal installment receipt;
     * the claim is then marked Paid. Any remainder is the nominee's cash
     * entitlement, paid out by the officer through the cashier (the
     * member's savings release is a normal deposit payment).
     */
    public function payClaim(InsuranceClaim $claim, User $actor): InsuranceClaim
    {
        return DB::transaction(function () use ($claim, $actor) {
            $claim = InsuranceClaim::lockForUpdate()->findOrFail($claim->id);
            if ($claim->status !== 'Approved') {
                throw ValidationException::withMessages(['status' => __('claim_not_approved')]);
            }
            if ($claim->loan_account_id) {
                $loan = LoanAccount::findOrFail($claim->loan_account_id);
                if (in_array($loan->status, ['Disbursed', 'Active'], true)) {
                    $outstanding = $this->money->outstanding($loan);
                    $settle = BigDecimal::of((string) $claim->amount);
                    if ($settle->isGreaterThan($outstanding['total'])) {
                        $settle = BigDecimal::of($outstanding['total']);
                    }
                    if ($settle->isPositive()) {
                        $this->money->receive($loan, [
                            'amount' => (string) $settle, 'penalty_charge' => null,
                            'comments' => 'Insurance claim #'.$claim->id.' settlement',
                        ], $actor);
                    }
                }
            }
            $claim->update(['status' => 'Paid', 'paid_by' => $actor->id, 'paid_at' => now()]);

            return $claim->fresh();
        });
    }
}
