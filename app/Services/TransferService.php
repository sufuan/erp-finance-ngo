<?php

namespace App\Services;

use App\Models\CashCount;
use App\Models\CashLedger;
use App\Models\CashTransfer;
use App\Models\FundTransferRequest;
use App\Models\User;
use App\Models\Voucher;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TransferService (Module 9, Steps 28–29) — inter-branch transfers and
 * officer→cashier cash handovers. All money moves via PostingService.
 *
 * Inter-branch semantics: value parks in the Inter-Branch Clearing
 * ledger (1130) between the sender's approve (send voucher, SENDER
 * branch: Dr Clearing / Cr Cash-or-Inventory) and the receiver's
 * receive (RECEIVE voucher, RECEIVING branch: Dr Cash-or-Inventory /
 * Cr Clearing). Each branch's own clearing balance is therefore its
 * net position with the rest of the organization, and the two legs
 * always cancel organization-wide.
 *
 * Cash handover semantics: a Pending transfer moves nothing. When the
 * cashier confirms, ONE Journal voucher carries the sub-ledger
 * movements (officer −amount, cashier +counted) with GL-neutral
 * Dr/Cr Cash-in-Hand lines; any shortage/excess between the recorded
 * amount and the physical count is booked by a small variance voucher
 * (shortage → Admin Expense, excess → Fee Income — Others) so the GL
 * cash total and the sum of officers' cash always move together.
 */
class TransferService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountsService $accounts,
        private readonly BusinessDayService $businessDays,
        private readonly NumberingService $numbering,
        private readonly BranchAccessService $branchAccess,
    ) {}

    // -- Inter-branch ------------------------------------------------------

    public function createRequest(User $actor, array $data): FundTransferRequest
    {
        if ((int) $data['from_branch_id'] === (int) $data['to_branch_id']) {
            throw ValidationException::withMessages(['to_branch_id' => __('transfer_same_branch')]);
        }
        $from = \App\Models\Branch::findOrFail($data['from_branch_id']);
        $request = FundTransferRequest::create([
            'request_no' => $this->numbering->nextDisplayId($from->id, 'fund_transfer'),
            'kind' => $data['kind'],
            'from_branch_id' => $from->id,
            'to_branch_id' => (int) $data['to_branch_id'],
            'amount' => $data['amount'] ?? 0,
            'item_name' => $data['item_name'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'comments' => $data['comments'] ?? null,
            'status' => 'Pending',
            'requested_by' => $actor->id,
        ]);

        return $request;
    }

    /** Sender side: approve the request and post the send voucher. */
    public function approveSend(FundTransferRequest $request, User $actor): FundTransferRequest
    {
        if ($request->status !== 'Pending') {
            throw new DomainException(__('transfer_not_pending'));
        }
        if (! $this->branchAccess->canAccess($actor, $request->from_branch_id)) {
            abort(403);
        }

        return DB::transaction(function () use ($request, $actor) {
            $voucher = null;
            if (BigDecimal::of((string) $request->amount)->isPositive()) {
                $clearing = $this->accounts->ledgerByCode(AccountsService::INTER_BRANCH_CLEARING);
                $asset = $this->accounts->ledgerByCode(
                    $request->kind === 'Product' ? AccountsService::INVENTORY : AccountsService::CASH_IN_HAND
                );
                $voucher = $this->posting->post([
                    'branch_id' => $request->from_branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                    'batch_number' => 'FTR-SEND-'.$request->id,
                    'comments' => 'Inter-branch '.$request->kind.' send '.$request->request_no,
                    'lines' => [
                        ['ledger_id' => $clearing->id, 'debit' => (string) $request->amount, 'credit' => '0.00'],
                        ['ledger_id' => $asset->id, 'debit' => '0.00', 'credit' => (string) $request->amount],
                    ],
                ]);
            }
            $request->update([
                'status' => 'Approved', 'approved_by' => $actor->id, 'approved_at' => now(),
                'send_voucher_id' => $voucher?->id,
            ]);

            return $request->fresh();
        });
    }

    /** Receiving side: only a user of the RECEIVING branch may receive. */
    public function receive(FundTransferRequest $request, User $actor): FundTransferRequest
    {
        if ($request->status !== 'Approved') {
            throw new DomainException(__('transfer_not_approved'));
        }
        if (! $this->branchAccess->canAccess($actor, $request->to_branch_id)) {
            abort(403);
        }

        return DB::transaction(function () use ($request, $actor) {
            $voucher = null;
            if (BigDecimal::of((string) $request->amount)->isPositive()) {
                $clearing = $this->accounts->ledgerByCode(AccountsService::INTER_BRANCH_CLEARING);
                $asset = $this->accounts->ledgerByCode(
                    $request->kind === 'Product' ? AccountsService::INVENTORY : AccountsService::CASH_IN_HAND
                );
                $voucher = $this->posting->post([
                    'branch_id' => $request->to_branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                    'batch_number' => 'FTR-RECV-'.$request->id,
                    'comments' => 'Inter-branch '.$request->kind.' receive '.$request->request_no,
                    'lines' => [
                        ['ledger_id' => $asset->id, 'debit' => (string) $request->amount, 'credit' => '0.00'],
                        ['ledger_id' => $clearing->id, 'debit' => '0.00', 'credit' => (string) $request->amount],
                    ],
                ]);
            }
            $request->update([
                'status' => 'Received', 'received_by' => $actor->id, 'received_at' => now(),
                'receive_voucher_id' => $voucher?->id,
            ]);

            return $request->fresh();
        });
    }

    /** Reject or cancel — only while Pending (nothing has moved yet). */
    public function closeRequest(FundTransferRequest $request, string $to): FundTransferRequest
    {
        if ($request->status !== 'Pending') {
            throw new DomainException(__('transfer_not_pending'));
        }
        $request->update(['status' => $to]);

        return $request;
    }

    // -- Cash management ---------------------------------------------------

    public function createCashTransfer(User $actor, int $branchId, int $fromUserId, int $toUserId, string $amount, ?string $comments): CashTransfer
    {
        if ($fromUserId === $toUserId) {
            throw ValidationException::withMessages(['to_user_id' => __('cash_transfer_same_user')]);
        }
        $amount = BigDecimal::of($amount);
        if ($amount->isZero() || $amount->isNegative()) {
            throw ValidationException::withMessages(['amount' => __('voucher_amount_must_be_positive')]);
        }
        $balance = CashLedger::where('user_id', $fromUserId)->value('current_balance') ?? '0';
        if (BigDecimal::of((string) $balance)->isLessThan($amount)) {
            throw ValidationException::withMessages(['amount' => __('cash_balance_insufficient')]);
        }

        return CashTransfer::create([
            'branch_id' => $branchId, 'from_user_id' => $fromUserId, 'to_user_id' => $toUserId,
            'amount' => (string) $amount, 'status' => 'Pending',
            'business_date' => $this->businessDays->currentDate($branchId),
            'entry_by' => $actor->id, 'comments' => $comments,
        ]);
    }

    /** Cashier confirms receipt with the physically counted amount. */
    public function receiveCashTransfer(CashTransfer $transfer, User $actor, string $counted): CashTransfer
    {
        if ($transfer->status !== 'Pending') {
            throw new DomainException(__('transfer_not_pending'));
        }
        if ($actor->id !== $transfer->to_user_id && ! $actor->hasRole('super-admin')) {
            abort(403);
        }
        $counted = BigDecimal::of($counted);
        if ($counted->isNegative()) {
            throw ValidationException::withMessages(['counted_amount' => __('counted_negative')]);
        }

        return DB::transaction(function () use ($transfer, $actor, $counted) {
            $cash = $this->accounts->ledgerByCode(AccountsService::CASH_IN_HAND);
            $amount = (string) $transfer->amount;

            // GL-neutral main voucher; the sub-ledger movements carry
            // the real split (officer −amount, cashier +counted).
            $voucher = $this->posting->post([
                'branch_id' => $transfer->branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                'batch_number' => 'CT-'.$transfer->id,
                'comments' => 'Cash handover #'.$transfer->id,
                'lines' => [
                    ['ledger_id' => $cash->id, 'debit' => $amount, 'credit' => '0.00'],
                    ['ledger_id' => $cash->id, 'debit' => '0.00', 'credit' => $amount],
                ],
                'movements' => [
                    ['account_type' => 'cash', 'account_id' => $transfer->from_user_id, 'tran_code' => 'CASH_OUT', 'amount' => '-'.$amount],
                    ['account_type' => 'cash', 'account_id' => $transfer->to_user_id, 'tran_code' => 'CASH_IN', 'amount' => (string) $counted],
                ],
            ]);

            // Variance: shortage books to Admin Expense, excess to Fee
            // Income — Others, so GL cash follows the physical count.
            $variance = $counted->minus(BigDecimal::of($amount));
            $varianceVoucher = null;
            if (! $variance->isZero()) {
                if ($variance->isNegative()) {
                    $shortage = (string) $variance->abs();
                    $expense = \App\Models\Ledger::where('ledger_code', '5020')->firstOrFail();
                    $varianceVoucher = $this->posting->post([
                        'branch_id' => $transfer->branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                        'batch_number' => 'CT-VAR-'.$transfer->id,
                        'comments' => 'Cash shortage on handover #'.$transfer->id,
                        'lines' => [
                            ['ledger_id' => $expense->id, 'debit' => $shortage, 'credit' => '0.00'],
                            ['ledger_id' => $cash->id, 'debit' => '0.00', 'credit' => $shortage],
                        ],
                    ]);
                } else {
                    $excess = (string) $variance;
                    $income = \App\Models\Ledger::where('ledger_code', '4035')->firstOrFail();
                    $varianceVoucher = $this->posting->post([
                        'branch_id' => $transfer->branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                        'batch_number' => 'CT-VAR-'.$transfer->id,
                        'comments' => 'Cash excess on handover #'.$transfer->id,
                        'lines' => [
                            ['ledger_id' => $cash->id, 'debit' => $excess, 'credit' => '0.00'],
                            ['ledger_id' => $income->id, 'debit' => '0.00', 'credit' => $excess],
                        ],
                    ]);
                }
            }

            $transfer->update([
                'status' => 'Received', 'counted_amount' => (string) $counted,
                'voucher_id' => $voucher->id, 'variance_voucher_id' => $varianceVoucher?->id,
                'received_by' => $actor->id, 'received_at' => now(),
            ]);

            return $transfer->fresh();
        });
    }

    public function cancelCashTransfer(CashTransfer $transfer): CashTransfer
    {
        if ($transfer->status !== 'Pending') {
            throw new DomainException(__('transfer_not_pending'));
        }
        $transfer->update(['status' => 'Cancelled']);

        return $transfer;
    }

    /** Day-end physical count against the cash sub-ledger expectation. */
    public function recordCashCount(User $actor, int $branchId, int $userId, string $counted): CashCount
    {
        $businessDate = $this->businessDays->currentDate($branchId);
        $expected = (string) (CashLedger::where('user_id', $userId)->value('current_balance') ?? '0');
        $difference = (string) BigDecimal::of($counted)->minus(BigDecimal::of($expected));

        return CashCount::updateOrCreate(
            ['branch_id' => $branchId, 'user_id' => $userId, 'business_date' => $businessDate],
            ['expected_balance' => $expected, 'counted_balance' => $counted, 'difference' => $difference, 'entry_by' => $actor->id],
        );
    }
}
