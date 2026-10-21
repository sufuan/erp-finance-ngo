<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\Member;
use Brick\Math\BigDecimal;
use Carbon\Carbon;

/**
 * Step 16 — proposal eligibility, all driven by the product's rules:
 *  - loan cycle (previous approved proposals + 1) within min/max_cycle;
 *  - active loans below max_concurrent_loans — counted per member AND
 *    per NID across all samities/branches (over-indebtedness check: the
 *    same person cannot dodge the concurrent limit via another branch);
 *  - total savings balance ≥ min_savings_balance;
 *  - membership age (from open_date) ≥ min_member_months.
 * "Active" at this stage = Approved proposals (each becomes a loan at
 * Step 17 opening). Returns a list of failed rule keys (empty = eligible).
 */
class LoanEligibilityService
{
    /** @return string[] failed rule keys */
    public function check(Member $member, LoanProduct $product): array
    {
        $failures = [];
        $previousLoans = LoanProposal::where('member_id', $member->id)
            ->where('status', 'Approved')->count();
        $cycle = $previousLoans + 1;
        if ($cycle < $product->min_cycle) {
            $failures[] = 'eligibility_cycle_min';
        }
        if ($product->max_cycle !== null && $cycle > $product->max_cycle) {
            $failures[] = 'eligibility_cycle_max';
        }

        $activeForMember = $previousLoans;
        if ($activeForMember >= $product->max_concurrent_loans) {
            $failures[] = 'eligibility_concurrent';
        }
        // Over-indebtedness: same NID, any branch/samity.
        if ($member->nid_hash) {
            $samePersonIds = Member::where('nid_hash', $member->nid_hash)->pluck('id');
            $activeForNid = LoanProposal::whereIn('member_id', $samePersonIds)
                ->where('status', 'Approved')->count();
            if ($activeForNid >= $product->max_concurrent_loans && $member->id !== null) {
                $failures[] = 'eligibility_over_indebted';
            }
        }

        if (BigDecimal::of((string) $product->min_savings_balance)->isPositive()) {
            $savings = DepositAccount::where('member_id', $member->id)
                ->whereNull('closing_date')->sum('current_balance');
            if (BigDecimal::of((string) $savings)->isLessThan((string) $product->min_savings_balance)) {
                $failures[] = 'eligibility_savings';
            }
        }

        if ($product->min_member_months > 0) {
            $since = $member->open_date ? Carbon::parse($member->open_date) : $member->created_at;
            if ($since === null || $since->diffInMonths(now()) < $product->min_member_months) {
                $failures[] = 'eligibility_membership_age';
            }
        }

        return $failures;
    }
}
