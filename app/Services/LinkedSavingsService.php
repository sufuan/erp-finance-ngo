<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;

/**
 * Client rule (2026-11-18) — every loan member holds ONE general
 * ("current") savings account: it is opened automatically with the
 * loan, receives the loan-linked savings deposit at disbursement,
 * and must be collected together with every installment. Which
 * deposit product plays that role is the `loan.linked_savings_product`
 * setting (default SAVINGS — "General Savings" / সাধারণ সঞ্চয়; the
 * CSAV product is the separate compulsory-savings product).
 */
class LinkedSavingsService
{
    public function __construct(
        private SettingsService $settings,
        private DepositAccountService $accounts,
    ) {}

    public function productCode(): string
    {
        return (string) $this->settings->get('loan.linked_savings_product', 'SAVINGS');
    }

    public function product(): ?DepositProduct
    {
        return DepositProduct::where('code', $this->productCode())->where('is_active', true)->first();
    }

    /** The member's active, non-closed account in the linked product. */
    public function activeAccount(Member $member): ?DepositAccount
    {
        $product = $this->product();
        if (! $product) {
            return null;
        }

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)
            ->where('is_active', true)->whereNull('closing_date')
            ->orderBy('id')->first();
    }

    /** Find the linked account, opening one (zero balance) when absent. */
    public function ensureAccount(Member $member, string $openingDate, ?int $actorId = null): ?DepositAccount
    {
        $existing = $this->activeAccount($member);
        if ($existing) {
            return $existing;
        }
        $product = $this->product();
        if (! $product) {
            return null;
        }

        return $this->accounts->open($member, $product, [
            'opening_date' => $openingDate,
        ], $actorId);
    }
}
