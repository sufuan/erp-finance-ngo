<?php

namespace App\Services;

use App\Models\DepositLien;
use App\Models\Employee;
use App\Models\GlMapping;
use App\Models\Guarantor;
use App\Models\LoanAccount;
use App\Models\LoanFee;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 17 — loan booking. Snapshots the product rate (override only with
 * `loans.rate-override`), enforces the MRA cap, generates the schedule
 * through ScheduleEngine (working-day shifted), records guarantors and
 * one `loan_fees` row per fee (posted in a single Receipt voucher via
 * `loan.fee.<type>` mappings), fills the proposal lien's loan_account_id,
 * and leaves the account in status Opened (disbursement is Step 18).
 * Eligibility is a proposal-stage rule (Step 16) and is not re-run here.
 */
class LoanOpeningService
{
    public function __construct(
        private ScheduleEngine $schedule,
        private LoanRateGuard $rateGuard,
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private NumberingService $numbering,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated opening form data
     */
    public function open(array $input, User $actor): LoanAccount
    {
        return DB::transaction(function () use ($input, $actor) {
            $product = LoanProduct::findOrFail($input['loan_product_id']);
            if (! $product->is_active) {
                throw ValidationException::withMessages(['loan_product_id' => __('product_inactive')]);
            }
            $proposal = ! empty($input['loan_proposal_id'])
                ? LoanProposal::lockForUpdate()->find($input['loan_proposal_id']) : null;
            if ($proposal) {
                if ($proposal->status !== 'Approved') {
                    throw ValidationException::withMessages(['loan_proposal_id' => __('proposal_not_approved')]);
                }
                if (LoanAccount::where('loan_proposal_id', $proposal->id)->exists()) {
                    throw ValidationException::withMessages(['loan_proposal_id' => __('proposal_already_opened')]);
                }
            }
            $member = ! empty($input['member_id']) ? Member::find($input['member_id']) : null;
            $employee = ! empty($input['employee_id']) ? Employee::find($input['employee_id']) : null;
            $branchId = $member?->branch_id ?? $employee?->branch_id ?? $proposal?->branch_id
                ?? (int) $actor->branches()->first()?->id;
            if (! $branchId) {
                throw ValidationException::withMessages(['member_id' => __('member_required')]);
            }

            // Rate precedence: product rate, officer override with permission.
            $rate = (string) $product->profit_rate;
            if (! empty($input['profit_rate']) && (float) $input['profit_rate'] !== (float) $product->profit_rate) {
                if (! $actor->can('loans.rate-override')) {
                    throw ValidationException::withMessages(['profit_rate' => __('rate_must_match_product')]);
                }
                $rate = (string) $input['profit_rate'];
            }
            $this->rateGuard->assertWithinCap($rate, $product->rate_method);

            $samity = $member?->samity;
            $rows = $this->schedule->rows(
                (string) $input['loan_amount'], $rate, $product->rate_method,
                $input['frequency'], (int) $input['no_of_installments'],
                $input['installment_from_date'], $branchId, $samity?->id, $samity?->samity_day,
            );
            $preview = $this->schedule->preview(
                (string) $input['loan_amount'], $rate, $product->rate_method,
                $input['frequency'], (int) $input['no_of_installments'], $input['installment_from_date'],
                $branchId, $samity?->id, $samity?->samity_day,
            );
            $lastRow = end($rows);
            // The loan's totals are the schedule's totals (the final row
            // absorbs rounding, so they can differ from the raw preview
            // by a few poisha — the schedule is authoritative).
            $scheduleTotal = collect($rows)->reduce(
                fn ($sum, $row) => $sum->plus($row['total_due']), \Brick\Math\BigDecimal::zero()
            );
            $scheduleProfit = $scheduleTotal->minus((string) $input['loan_amount']);

            $loan = LoanAccount::create([
                'loan_number' => $this->numbering->nextDisplayId($branchId, 'loan_account'),
                'branch_id' => $branchId,
                'member_id' => $member?->id, 'employee_id' => $employee?->id,
                'loan_product_id' => $product->id, 'loan_proposal_id' => $proposal?->id,
                'opening_date' => $input['opening_date'], 'loan_purpose' => $input['loan_purpose'] ?? null,
                'loan_amount' => $input['loan_amount'], 'down_payment' => $input['down_payment'] ?? 0,
                'block_account' => (bool) ($input['block_account'] ?? false),
                'frequency' => $input['frequency'], 'no_of_installments' => (int) $input['no_of_installments'],
                'installment_amount' => $preview['installment_amount'],
                'actual_installment_amount' => $preview['actual_installment_amount'],
                'extra_installment_amount' => $preview['extra_installment_amount'],
                'last_installment_amount' => (string) $lastRow['total_due'],
                'profit_rate' => $rate, 'profit_amount' => (string) $scheduleProfit,
                'total_repayment' => (string) $scheduleTotal,
                'installment_from_date' => $input['installment_from_date'],
                'installment_expire_date' => $lastRow['due_date'],
                'payment_type' => $input['payment_type'] ?? null,
                'payment_to_account' => $input['payment_to_account'] ?? null,
                'invoice_id' => $input['invoice_id'] ?? null,
                'invoice_number' => $input['invoice_number'] ?? null,
                'status' => 'Opened',
                'installment_reminder' => (bool) ($input['installment_reminder'] ?? false),
                'overdue_reminder' => (bool) ($input['overdue_reminder'] ?? false),
            ]);

            foreach ($rows as $row) {
                LoanSchedule::create(['loan_account_id' => $loan->id, ...$row]);
            }
            foreach (($input['guarantors'] ?? []) as $guarantor) {
                if (empty($guarantor['name'])) {
                    continue;
                }
                Guarantor::create([
                    'loan_account_id' => $loan->id, 'name' => $guarantor['name'],
                    'relation' => $guarantor['relation'] ?? null,
                    'phone' => $guarantor['phone'] ?? null,
                    'address' => $guarantor['address'] ?? null,
                    'national_id' => $guarantor['national_id'] ?? null,
                ]);
            }

            // Fees: one loan_fees row each; a single Receipt voucher posts
            // them all (cash in against each fee's income ledger).
            $feeRows = [];
            $feeLines = [];
            $feeTotal = \Brick\Math\BigDecimal::zero();
            foreach (LoanFee::TYPES as $type) {
                $amount = (string) ($input['fees'][$type] ?? '0');
                if (! \Brick\Math\BigDecimal::of($amount)->isPositive()) {
                    continue;
                }
                $mapping = GlMapping::byKey('loan.fee.'.$type);
                $feeRows[] = LoanFee::create([
                    'loan_account_id' => $loan->id, 'fee_type' => $type,
                    'amount' => $amount, 'ledger_id' => $mapping->credit_ledger_id,
                ]);
                $feeLines[] = ['mapping_key' => 'loan.fee.'.$type, 'amount' => $amount, 'loan_account_id' => $loan->id];
                $feeTotal = $feeTotal->plus($amount);
            }
            if ($feeLines !== []) {
                $voucher = $this->posting->post([
                    'branch_id' => $branchId, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
                    'transaction_date' => $this->businessDays->currentDate($branchId),
                    'comments' => 'Loan fees for '.$loan->loan_number,
                    'lines' => $feeLines,
                    'movements' => [
                        ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => (string) $feeTotal],
                    ],
                ]);
                foreach ($feeRows as $feeRow) {
                    $feeRow->update(['voucher_id' => $voucher->id]);
                }
            }

            if ($proposal) {
                DepositLien::where('loan_proposal_id', $proposal->id)
                    ->whereNull('loan_account_id')
                    ->update(['loan_account_id' => $loan->id]);
            }

            return $loan->fresh();
        });
    }
}
