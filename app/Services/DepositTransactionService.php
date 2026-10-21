<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\DepositTransaction;
use App\Models\DepositTransfer;
use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 11 — deposit money movement. Every receive/payment/transfer/share
 * operation posts through PostingService (the only money engine); this
 * service adds the product rules around it: account locks (FOR UPDATE on
 * deposit_accounts / members, never on GL ledgers), minimum-balance and
 * lien guards, the product detail rows, and the cached-balance resync
 * from the account_transactions source of truth.
 *
 * Cancellation is latest-movement-only: cancelling an older movement
 * would invalidate the running balances chained behind it (full reversal
 * entries arrive with the Step 27 EOD tooling).
 */
class DepositTransactionService
{
    public function __construct(
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private DepositAccountService $accounts,
    ) {}

    public function productLabel(DepositProduct $product): string
    {
        return match ($product->code) {
            'DPS', 'LTS' => 'DPS',
            'FDR' => 'FDR',
            default => 'Savings',
        };
    }

    /** Uncleared withdrawal-block total holding back debits (Step 12). */
    public function withdrawalBlockTotal(DepositAccount $account): string
    {
        $sum = \App\Models\DepositBlock::where('account_id', $account->id)
            ->whereNull('clear_date')->where('withdrawal_block', true)->sum('block_amount');

        return (string) BigDecimal::of((string) $sum)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
    }

    public function receive(DepositAccount $account, string $amount, ?string $documentNumber, ?string $comments, User $actor): DepositTransaction
    {
        return DB::transaction(function () use ($account, $amount, $documentNumber, $comments, $actor) {
            $account = $this->lockedAccount($account->id);
            $this->assertActive($account);
            // Step 12: an uncleared deposit block stops money coming in.
            if (\App\Models\DepositBlock::where('account_id', $account->id)
                ->whereNull('clear_date')->where('deposit_block', true)->exists()) {
                throw ValidationException::withMessages(['account_id' => __('deposits_blocked')]);
            }
            $businessDate = $this->businessDays->currentDate($account->branch_id);

            $detail = DepositTransaction::create([
                'branch_id' => $account->branch_id, 'account_id' => $account->id,
                'member_id' => $account->member_id, 'tran_type' => 'Receive',
                'product' => $this->productLabel($account->product), 'amount' => $amount,
                'tran_date' => $businessDate, 'business_date' => $businessDate,
                'document_number' => $documentNumber, 'comments' => $comments,
                'entry_by' => $actor->id,
            ]);

            $voucher = $this->posting->post([
                'branch_id' => $account->branch_id, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
                'comments' => $comments,
                'lines' => [[
                    'mapping_key' => $account->product->gl_mapping_prefix.'.receive',
                    'amount' => $amount, 'deposit_account_id' => $account->id, 'document_number' => $documentNumber,
                ]],
                'movements' => [
                    ['account_type' => 'deposit', 'account_id' => $account->id, 'tran_code' => 'RECEIVE', 'amount' => $amount, 'ref_type' => 'deposit_transaction', 'ref_id' => $detail->id],
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => $amount],
                ],
            ]);
            $detail->update(['voucher_id' => $voucher->id]);
            $this->resyncCache($account);

            return $detail->fresh();
        });
    }

    public function payment(DepositAccount $account, string $amount, ?string $documentNumber, ?string $comments, User $actor): DepositTransaction
    {
        return DB::transaction(function () use ($account, $amount, $documentNumber, $comments, $actor) {
            $account = $this->lockedAccount($account->id);
            $this->assertActive($account);
            $this->assertDebitAllowed($account, $amount);
            $businessDate = $this->businessDays->currentDate($account->branch_id);

            $detail = DepositTransaction::create([
                'branch_id' => $account->branch_id, 'account_id' => $account->id,
                'member_id' => $account->member_id, 'tran_type' => 'Payment',
                'product' => $this->productLabel($account->product), 'amount' => $amount,
                'tran_date' => $businessDate, 'business_date' => $businessDate,
                'document_number' => $documentNumber, 'comments' => $comments,
                'entry_by' => $actor->id,
            ]);

            $voucher = $this->posting->post([
                'branch_id' => $account->branch_id, 'voucher_type' => 'Payment', 'entry_by' => $actor->id,
                'comments' => $comments,
                'lines' => [[
                    'mapping_key' => $account->product->gl_mapping_prefix.'.pay',
                    'amount' => $amount, 'deposit_account_id' => $account->id, 'document_number' => $documentNumber,
                ]],
                'movements' => [
                    ['account_type' => 'deposit', 'account_id' => $account->id, 'tran_code' => 'PAYMENT', 'amount' => '-'.$amount, 'ref_type' => 'deposit_transaction', 'ref_id' => $detail->id],
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_OUT', 'amount' => '-'.$amount],
                ],
            ]);
            $detail->update(['voucher_id' => $voucher->id]);
            $this->resyncCache($account);

            return $detail->fresh();
        });
    }

    public function transfer(DepositAccount $from, DepositAccount $to, string $amount, ?string $documentNumber, ?string $comments, User $actor): DepositTransfer
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => __('invalid_transfer_same_account')]);
        }

        return DB::transaction(function () use ($from, $to, $amount, $documentNumber, $comments, $actor) {
            // Consistent lock order (ascending id) across both accounts.
            $locked = collect([$from->id, $to->id])->sort()->map(fn ($id) => $this->lockedAccount($id));
            $fromLocked = $locked->firstWhere('id', $from->id);
            $toLocked = $locked->firstWhere('id', $to->id);
            $this->assertActive($fromLocked);
            $this->assertActive($toLocked);
            $this->assertDebitAllowed($fromLocked, $amount);
            $businessDate = $this->businessDays->currentDate($fromLocked->branch_id);

            $transfer = DepositTransfer::create([
                'branch_id' => $fromLocked->branch_id, 'from_account_id' => $from->id, 'to_account_id' => $to->id,
                'amount' => $amount, 'transfer_date' => $businessDate,
                'document_number' => $documentNumber, 'comments' => $comments, 'entry_by' => $actor->id,
            ]);

            $this->posting->post([
                'branch_id' => $fromLocked->branch_id, 'voucher_type' => 'Contra', 'entry_by' => $actor->id,
                'comments' => $comments,
                'lines' => [[
                    'mapping_key' => 'deposit.transfer', 'amount' => $amount,
                    'deposit_account_id' => $from->id, 'document_number' => $documentNumber,
                ]],
                'movements' => [
                    ['account_type' => 'deposit', 'account_id' => $from->id, 'tran_code' => 'TRANSFER_OUT', 'amount' => '-'.$amount, 'ref_type' => 'deposit_transfer', 'ref_id' => $transfer->id],
                    ['account_type' => 'deposit', 'account_id' => $to->id, 'tran_code' => 'TRANSFER_IN', 'amount' => $amount, 'ref_type' => 'deposit_transfer', 'ref_id' => $transfer->id],
                ],
            ]);
            $this->resyncCache($fromLocked);
            $this->resyncCache($toLocked);

            return $transfer->fresh();
        });
    }

    public function cancelTransaction(DepositTransaction $detail, User $by, string $reason): void
    {
        DB::transaction(function () use ($detail, $by, $reason) {
            $account = $this->lockedAccount($detail->account_id);
            $this->assertLatestMovement('deposit', $account->id, $detail->voucher_id);
            $this->posting->cancel(Voucher::findOrFail($detail->voucher_id), $by, $reason);
            $detail->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
            $this->resyncCache($account);
        });
    }

    public function cancelTransfer(DepositTransfer $transfer, User $by, string $reason): void
    {
        DB::transaction(function () use ($transfer, $by, $reason) {
            $voucherId = AccountTransaction::where('ref_type', 'deposit_transfer')
                ->where('ref_id', $transfer->id)->value('voucher_id');
            $from = $this->lockedAccount($transfer->from_account_id);
            $to = $this->lockedAccount($transfer->to_account_id);
            $this->assertLatestMovement('deposit', $from->id, $voucherId);
            $this->assertLatestMovement('deposit', $to->id, $voucherId);
            $this->posting->cancel(Voucher::findOrFail($voucherId), $by, $reason);
            $transfer->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
            $this->resyncCache($from);
            $this->resyncCache($to);
        });
    }

    /** Share capital receive — posts to members.share_amount, no deposit account. */
    public function shareReceive(Member $member, string $amount, ?string $documentNumber, ?string $comments, User $actor): Voucher
    {
        return DB::transaction(function () use ($member, $amount, $documentNumber, $comments, $actor) {
            $member = Member::lockForUpdate()->findOrFail($member->id);
            $voucher = $this->posting->post([
                'branch_id' => $member->branch_id, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
                'comments' => $comments,
                'lines' => [['mapping_key' => 'share.receive', 'amount' => $amount, 'document_number' => $documentNumber]],
                'movements' => [
                    ['account_type' => 'share', 'account_id' => $member->id, 'tran_code' => 'SHARE_RECEIVE', 'amount' => $amount, 'ref_type' => 'member', 'ref_id' => $member->id],
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => $amount],
                ],
            ]);
            $this->resyncShare($member);

            return $voucher;
        });
    }

    public function sharePayment(Member $member, string $amount, ?string $documentNumber, ?string $comments, User $actor): Voucher
    {
        return DB::transaction(function () use ($member, $amount, $documentNumber, $comments, $actor) {
            $member = Member::lockForUpdate()->findOrFail($member->id);
            if (BigDecimal::of($amount)->isGreaterThan(BigDecimal::of((string) $member->share_amount))) {
                throw ValidationException::withMessages(['amount' => __('insufficient_share_balance')]);
            }
            $voucher = $this->posting->post([
                'branch_id' => $member->branch_id, 'voucher_type' => 'Payment', 'entry_by' => $actor->id,
                'comments' => $comments,
                'lines' => [['mapping_key' => 'share.pay', 'amount' => $amount, 'document_number' => $documentNumber]],
                'movements' => [
                    ['account_type' => 'share', 'account_id' => $member->id, 'tran_code' => 'SHARE_PAYMENT', 'amount' => '-'.$amount, 'ref_type' => 'member', 'ref_id' => $member->id],
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_OUT', 'amount' => '-'.$amount],
                ],
            ]);
            $this->resyncShare($member);

            return $voucher;
        });
    }

    /** Recompute the cached balances of one deposit account from the sub-ledger. */
    public function resyncCache(DepositAccount $account): void
    {
        $rows = AccountTransaction::where('account_type', 'deposit')
            ->where('account_id', $account->id)->whereNull('cancel_at')->orderBy('id')->get();
        $credit = BigDecimal::zero();
        $debit = BigDecimal::zero();
        foreach ($rows as $row) {
            $amount = BigDecimal::of((string) $row->amount);
            if ($amount->isPositive()) {
                $credit = $credit->plus($amount);
            } else {
                $debit = $debit->plus($amount->abs());
            }
        }
        $account->update([
            'total_credit' => (string) $credit->toScale(2),
            'total_debit' => (string) $debit->toScale(2),
            'current_balance' => (string) ($rows->last() ? BigDecimal::of((string) $rows->last()->balance_after)->toScale(2) : BigDecimal::zero()->toScale(2)),
        ]);
    }

    private function resyncShare(Member $member): void
    {
        $latest = AccountTransaction::where('account_type', 'share')
            ->where('account_id', $member->id)->whereNull('cancel_at')->orderByDesc('id')->first();
        $member->update(['share_amount' => $latest ? (string) $latest->balance_after : '0.00']);
    }

    private function lockedAccount(int $id): DepositAccount
    {
        return DepositAccount::with('product')->lockForUpdate()->findOrFail($id);
    }

    private function assertActive(DepositAccount $account): void
    {
        if (! $account->is_active || $account->closing_date !== null) {
            throw ValidationException::withMessages(['account_id' => __('account_closed_or_inactive')]);
        }
    }

    /** Minimum-balance + lien + block guards shared by payments and transfer-outs. */
    private function assertDebitAllowed(DepositAccount $account, string $amount): void
    {
        $account->loadMissing('product');
        $after = BigDecimal::of((string) $account->current_balance)->minus($amount);
        if ($after->isLessThan(BigDecimal::of((string) $account->product->minimum_balance))) {
            throw ValidationException::withMessages(['amount' => __('below_minimum_balance')]);
        }
        // Step 12: uncleared withdrawal blocks reserve their amount, like liens.
        $reserved = BigDecimal::of($this->accounts->activeLienTotal($account))
            ->plus($this->withdrawalBlockTotal($account));
        if ($after->isLessThan($reserved)) {
            throw ValidationException::withMessages(['amount' => __('amount_blocked')]);
        }
        $this->accounts->assertWithdrawable($account, $amount);
    }

    private function assertLatestMovement(string $type, int $accountId, ?int $voucherId): void
    {
        $latest = AccountTransaction::where('account_type', $type)
            ->where('account_id', $accountId)->whereNull('cancel_at')->orderByDesc('id')->first();
        if (! $latest || (int) $latest->voucher_id !== (int) $voucherId) {
            throw ValidationException::withMessages(['cancel' => __('only_latest_can_be_cancelled')]);
        }
    }
}
