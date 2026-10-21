<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Step 10 — deposit account opening rules and closing maths. Money itself
 * only ever moves through PostingService (Step 11+); this service owns the
 * account lifecycle facts: numbering, profit-rate snapshot, maturity
 * computation, lien guards and the premature-closing penalty preview that
 * Step 12's settlement posts.
 */
class DepositAccountService
{
    public function __construct(private NumberingService $numbering) {}

    /**
     * @param  array  $data  title, address, phone, opening_date, profit_rate?,
     *                       installment_amount?, term_months?, nominee_id?
     */
    public function open(Member $member, DepositProduct $product, array $data, ?int $actorId = null): DepositAccount
    {
        $opening = Carbon::parse($data['opening_date']);
        $months = isset($data['term_months']) && $data['term_months'] !== null
            ? (int) $data['term_months']
            : $product->maturity_months;

        return DepositAccount::create([
            'account_number' => $this->numbering->nextDisplayId($member->branch_id, 'deposit_account'),
            'branch_id' => $member->branch_id,
            'member_id' => $member->id,
            'deposit_product_id' => $product->id,
            'title' => $data['title'] ?? $member->name,
            'address' => $data['address'] ?? $member->present_address,
            'phone' => $data['phone'] ?? $member->mobile,
            'opening_date' => $opening->toDateString(),
            'profit_rate' => $data['profit_rate'] ?? $product->default_profit_rate,
            'installment_amount' => $data['installment_amount'] ?? null,
            'maturity_date' => $months ? $opening->copy()->addMonths($months)->toDateString() : null,
            'nominee_id' => $data['nominee_id'] ?? $member->nominees()->orderBy('id')->value('id'),
            'credit_limit' => $data['credit_limit'] ?? 0,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    /** Total amount currently held under Active liens. */
    public function activeLienTotal(DepositAccount $account): string
    {
        return (string) BigDecimal::of((string) $account->activeLiens()->sum('amount'))
            ->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Closing guard (binding): an account with an Active lien cannot be
     * closed, and withdrawals may not take the balance below the lien.
     */
    public function assertClosable(DepositAccount $account): void
    {
        if ($account->activeLiens()->exists()) {
            throw ValidationException::withMessages([
                'account' => __('account_has_active_lien'),
            ]);
        }
    }

    public function assertWithdrawable(DepositAccount $account, string $amount): void
    {
        $available = BigDecimal::of((string) $account->current_balance)
            ->minus($this->activeLienTotal($account));
        if (BigDecimal::of($amount)->isGreaterThan($available)) {
            throw ValidationException::withMessages([
                'amount' => __('withdrawal_blocked_by_lien'),
            ]);
        }
    }

    /**
     * Premature-closing figures: closing before the maturity date deducts
     * the product's premature penalty rate from the balance. Step 12 posts
     * exactly these numbers.
     *
     * @return array{balance: string, penalty: string, payable: string, is_premature: bool}
     */
    public function prematureClosePreview(DepositAccount $account, ?string $closingDate = null): array
    {
        $account->loadMissing('product');
        $balance = BigDecimal::of((string) $account->current_balance);
        $date = $closingDate ? Carbon::parse($closingDate) : Carbon::today();
        $isPremature = $account->maturity_date && $date->lt($account->maturity_date);
        $penalty = $isPremature
            ? $balance->multipliedBy((string) $account->product->premature_penalty_rate)->dividedBy(100, 2, RoundingMode::HalfUp)
            : BigDecimal::zero()->toScale(2);

        return [
            'balance' => (string) $balance,
            'penalty' => (string) $penalty,
            'payable' => (string) $balance->minus($penalty),
            'is_premature' => (bool) $isPremature,
        ];
    }
}
