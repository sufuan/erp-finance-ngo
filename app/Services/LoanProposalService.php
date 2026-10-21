<?php

namespace App\Services;

use App\Models\DepositLien;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 16 — proposal intake + decisions. Submission recomputes the
 * schedule server-side (ScheduleEngine), enforces the rate precedence
 * (product rate; override only with `loans.rate-override`) and the MRA
 * cap, and runs the eligibility rules. Approval creates the FDR lien
 * (loan_proposal_id set now; loan_account_id arrives at Step 17 opening).
 */
class LoanProposalService
{
    public function __construct(
        private ScheduleEngine $schedule,
        private LoanRateGuard $rateGuard,
        private LoanEligibilityService $eligibility,
    ) {}

    /**
     * @param  array{loan_product_id: int, proposal_date: string, loan_purpose: ?string, loan_amount: string, fdr_account_id: ?int, frequency: string, no_of_installments: int, profit_rate: ?string, installment_from_date: string, guarantors: ?array}  $input
     */
    public function submit(Member $member, array $input, User $actor): LoanProposal
    {
        $product = LoanProduct::findOrFail($input['loan_product_id']);
        if (! $product->is_active) {
            throw ValidationException::withMessages(['loan_product_id' => __('product_inactive')]);
        }
        // Rate precedence (Step 15): product rate wins; an officer override
        // needs the permission.
        $rate = (string) $product->profit_rate;
        if (! empty($input['profit_rate']) && (float) $input['profit_rate'] !== (float) $product->profit_rate) {
            if (! $actor->can('loans.rate-override')) {
                throw ValidationException::withMessages(['profit_rate' => __('rate_must_match_product')]);
            }
            $rate = (string) $input['profit_rate'];
        }
        $this->rateGuard->assertWithinCap($rate, $product->rate_method);

        $amount = (string) $input['loan_amount'];
        if (\Brick\Math\BigDecimal::of($amount)->isLessThan((string) $product->min_amount)
            || ($product->max_amount > 0 && \Brick\Math\BigDecimal::of($amount)->isGreaterThan((string) $product->max_amount))) {
            throw ValidationException::withMessages(['loan_amount' => __('amount_out_of_range')]);
        }
        $installments = (int) $input['no_of_installments'];
        if ($installments < $product->min_term || $installments > $product->max_term) {
            throw ValidationException::withMessages(['no_of_installments' => __('term_out_of_range')]);
        }
        if (! in_array($input['frequency'], $product->allowed_frequencies ?? [], true)) {
            throw ValidationException::withMessages(['frequency' => __('frequency_not_allowed')]);
        }

        $failures = $this->eligibility->check($member, $product);
        if ($failures !== []) {
            throw ValidationException::withMessages([
                'member_id' => collect($failures)->map(fn ($key) => __($key))->implode(' '),
            ]);
        }

        $preview = $this->schedule->preview(
            $amount, $rate, $product->rate_method, $input['frequency'],
            $installments, $input['installment_from_date'],
        );

        return LoanProposal::create([
            'branch_id' => $member->branch_id, 'member_id' => $member->id,
            'samity_id' => $member->samity_id, 'loan_product_id' => $product->id,
            'proposal_date' => $input['proposal_date'], 'loan_purpose' => $input['loan_purpose'] ?? null,
            'loan_amount' => $amount, 'fdr_account_id' => $input['fdr_account_id'] ?? null,
            'frequency' => $input['frequency'], 'no_of_installments' => $installments,
            'installment_amount' => $preview['installment_amount'],
            'profit_rate' => $rate, 'profit_amount' => $preview['profit_amount'],
            'total_repayment' => $preview['total_repayment'],
            'installment_from_date' => $input['installment_from_date'],
            'installment_expire_date' => $preview['installment_expire_date'],
            'guarantors' => $input['guarantors'] ?? null,
            'status' => 'Submitted', 'created_by' => $actor->id,
        ]);
    }

    public function approve(LoanProposal $proposal, User $actor): LoanProposal
    {
        return DB::transaction(function () use ($proposal, $actor) {
            $proposal = LoanProposal::lockForUpdate()->findOrFail($proposal->id);
            if ($proposal->status !== 'Submitted') {
                throw ValidationException::withMessages(['status' => __('proposal_already_decided')]);
            }
            $proposal->update(['status' => 'Approved', 'decided_by' => $actor->id, 'decided_at' => now()]);
            // Binding FDR lien: created at approval with loan_proposal_id;
            // Step 17 fills loan_account_id when the loan opens.
            if ($proposal->fdr_account_id) {
                DepositLien::create([
                    'deposit_account_id' => $proposal->fdr_account_id,
                    'loan_proposal_id' => $proposal->id,
                    'loan_account_id' => null,
                    'amount' => $proposal->loan_amount,
                    'status' => 'Active',
                    'created_by' => $actor->id,
                ]);
            }

            return $proposal->fresh();
        });
    }

    public function reject(LoanProposal $proposal, User $actor): LoanProposal
    {
        if ($proposal->status !== 'Submitted') {
            throw ValidationException::withMessages(['status' => __('proposal_already_decided')]);
        }
        $proposal->update(['status' => 'Rejected', 'decided_by' => $actor->id, 'decided_at' => now()]);

        return $proposal->fresh();
    }
}
