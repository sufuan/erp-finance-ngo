<?php

namespace App\Services;

use App\Events\CollectionPosted;
use App\Models\AccountTransaction;
use App\Models\ChequeBookIssue;
use App\Models\CollectionBatch;
use App\Models\CollectionLine;
use App\Models\DepositAccount;
use App\Models\DepositTransaction;
use App\Models\LoanAccount;
use App\Models\LoanReceive;
use App\Models\Member;
use App\Models\OtherCollection;
use App\Models\PassbookIssue;
use App\Models\Samity;
use App\Models\User;
use App\Models\Voucher;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 21–23 — Collection module engine.
 *
 * A batch fans out to the EXISTING money engines — deposit receives via
 * DepositTransactionService, loan installments via LoanMoneyService
 * (allocation order + ScheduleEngine figures stay theirs), share money
 * via DepositTransactionService::shareReceive. This service adds only
 * the batch/line bookkeeping, the grid + receivable computation, batch
 * totals, and delegation of cancellations to the engines' latest-only
 * rules. Every engine receive also credits the collecting officer's
 * cash-in-hand through PostingService movements, as elsewhere.
 *
 * Atomicity: the whole sheet posts inside one DB transaction. If any
 * row fails, everything rolls back and the thrown ValidationException
 * carries one message per failed row (member + account + reason).
 */
class CollectionService
{
    public function __construct(
        private DepositTransactionService $depositTxns,
        private LoanMoneyService $loanMoney,
        private PostingService $posting,
        private BusinessDayService $businessDays,
    ) {}

    private function money(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }

    /** Expected collection for a deposit account = its installment amount. */
    public function depositReceivable(DepositAccount $account): string
    {
        return $this->money(BigDecimal::of((string) ($account->installment_amount ?? 0)));
    }

    /**
     * Expected collection for a loan = amount due today (overdue + due
     * rows + penalty, from LoanMoneyService); when nothing is due yet,
     * the regular installment capped at the total outstanding.
     */
    public function loanReceivable(LoanAccount $loan): string
    {
        $due = BigDecimal::of($this->loanMoney->todaysDue($loan));
        if ($due->isPositive()) {
            return $this->money($due);
        }
        $outstanding = BigDecimal::of($this->loanMoney->outstanding($loan)['total']);
        $installment = BigDecimal::of((string) ($loan->installment_amount ?? 0));

        return $this->money($installment->isLessThan($outstanding) ? $installment : $outstanding);
    }

    /**
     * The collection grid for a set of members: one row per member, one
     * editable cell per account the member holds (deposit accounts, then
     * loans, then a share cell), each with its receivable prefilled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gridForMembers(iterable $members): array
    {
        $rows = [];
        foreach ($members as $member) {
            $accounts = [];
            $deposits = DepositAccount::with('product:id,code,name_en')
                ->where('member_id', $member->id)->where('is_active', true)
                ->whereNull('closing_date')->orderBy('id')->get();
            foreach ($deposits as $account) {
                $receivable = $this->depositReceivable($account);
                $accounts[] = [
                    'account_type' => 'deposit',
                    'account_id' => $account->id,
                    'label' => $account->account_number,
                    'product_code' => $account->product?->code,
                    'product_label' => $this->depositTxns->productLabel($account->product),
                    'balance' => (string) $account->current_balance,
                    'receivable' => $receivable,
                    'amount' => $receivable,
                ];
            }
            $loans = LoanAccount::where('member_id', $member->id)
                ->whereIn('status', ['Disbursed', 'Active'])->orderBy('id')->get();
            foreach ($loans as $loan) {
                $receivable = $this->loanReceivable($loan);
                $accounts[] = [
                    'account_type' => 'loan',
                    'account_id' => $loan->id,
                    'label' => $loan->loan_number,
                    'product_code' => null,
                    'product_label' => 'Loan',
                    'balance' => $this->loanMoney->outstanding($loan)['total'],
                    'receivable' => $receivable,
                    'amount' => $receivable,
                ];
            }
            $accounts[] = [
                'account_type' => 'share',
                'account_id' => $member->id,
                'label' => $member->member_no,
                'product_code' => null,
                'product_label' => 'Share',
                'balance' => (string) $member->share_amount,
                'receivable' => '0.00',
                'amount' => '0.00',
            ];
            $rows[] = [
                'member_id' => $member->id,
                'member_no' => $member->member_no,
                'member_name' => $member->name,
                'accounts' => $accounts,
            ];
        }

        return $rows;
    }

    public function gridForSamity(Samity $samity): array
    {
        $members = Member::where('samity_id', $samity->id)
            ->where('status', 'Active')->orderBy('member_no')->get();

        return $this->gridForMembers($members);
    }

    public function gridForMember(Member $member): array
    {
        return $this->gridForMembers([$member]);
    }

    /**
     * Post a batch. $input: kind, samity_id?, member_id?, lines[] each
     * {member_id, account_type, account_id, amount}. Zero-amount lines
     * are skipped. Returns the posted batch (with lines).
     */
    public function postBatch(User $actor, array $input): CollectionBatch
    {
        $kind = $input['kind'];
        $samity = ! empty($input['samity_id']) ? Samity::findOrFail($input['samity_id']) : null;
        $member = ! empty($input['member_id']) ? Member::findOrFail($input['member_id']) : null;
        $branchId = $samity?->branch_id ?? $member?->branch_id;
        if (! $branchId) {
            throw ValidationException::withMessages(['samity_id' => __('collection_target_required')]);
        }
        $businessDate = $this->businessDays->currentDate($branchId);

        // Member set whose receivables define the batch totals.
        $memberIds = $kind === 'member' && $member
            ? [$member->id]
            : Member::where('samity_id', $samity?->id)->where('status', 'Active')->pluck('id')->all();

        $lines = collect($input['lines'] ?? [])
            ->filter(fn ($l) => isset($l['amount']) && BigDecimal::of((string) $l['amount'])->isPositive())
            ->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => [__('collection_no_amounts')]]);
        }

        return DB::transaction(function () use ($actor, $kind, $samity, $member, $branchId, $businessDate, $memberIds, $lines) {
            $batch = CollectionBatch::create([
                'branch_id' => $branchId,
                'samity_id' => $samity?->id,
                'member_id' => $kind === 'member' ? $member?->id : null,
                'kind' => $kind,
                'tran_date' => $businessDate,
                'business_date' => $businessDate,
                'status' => 'Posted',
                'entry_by' => $actor->id,
                'entry_at' => now(),
            ]);

            $errors = [];
            $loanReceive = BigDecimal::zero();
            $depositReceive = BigDecimal::zero();
            $shareReceive = BigDecimal::zero();

            foreach ($lines as $i => $line) {
                $amount = $this->money(BigDecimal::of((string) $line['amount']));
                $lineMember = Member::find((int) $line['member_id']);
                $label = ($lineMember?->member_no ?? '#'.$line['member_id']).' / '.($line['account_type'] ?? '?').' '.($line['account_id'] ?? '');
                try {
                    $refType = null;
                    $refId = null;
                    $productCode = null;
                    $receivable = '0.00';
                    if ($line['account_type'] === 'deposit') {
                        $account = DepositAccount::with('product')->findOrFail((int) $line['account_id']);
                        if ((int) $account->member_id !== (int) $line['member_id']) {
                            throw ValidationException::withMessages(['account_id' => __('account_member_mismatch')]);
                        }
                        $receivable = $this->depositReceivable($account);
                        $detail = $this->depositTxns->receive($account, $amount, null, 'Collection batch #'.$batch->id, $actor);
                        $refType = 'deposit_transaction';
                        $refId = $detail->id;
                        $productCode = $account->product?->code;
                        $depositReceive = $depositReceive->plus($amount);
                    } elseif ($line['account_type'] === 'loan') {
                        $loan = LoanAccount::findOrFail((int) $line['account_id']);
                        if ((int) $loan->member_id !== (int) $line['member_id']) {
                            throw ValidationException::withMessages(['account_id' => __('account_member_mismatch')]);
                        }
                        $receivable = $this->loanReceivable($loan);
                        $receive = $this->loanMoney->receive($loan, [
                            'amount' => $amount,
                            'comments' => 'Collection batch #'.$batch->id,
                        ], $actor);
                        $refType = 'loan_receive';
                        $refId = $receive->id;
                        $loanReceive = $loanReceive->plus($amount);
                    } elseif ($line['account_type'] === 'share') {
                        if ((int) $line['account_id'] !== (int) $line['member_id'] || ! $lineMember) {
                            throw ValidationException::withMessages(['account_id' => __('account_member_mismatch')]);
                        }
                        $voucher = $this->depositTxns->shareReceive($lineMember, $amount, null, 'Collection batch #'.$batch->id, $actor);
                        $refType = 'voucher';
                        $refId = $voucher->id;
                        $shareReceive = $shareReceive->plus($amount);
                    } else {
                        throw ValidationException::withMessages(['account_type' => __('invalid_account_type')]);
                    }
                    CollectionLine::create([
                        'batch_id' => $batch->id,
                        'member_id' => (int) $line['member_id'],
                        'account_type' => $line['account_type'],
                        'account_id' => (int) $line['account_id'],
                        'deposit_product_code' => $productCode,
                        'receivable' => $receivable,
                        'amount' => $amount,
                        'ref_type' => $refType,
                        'ref_id' => $refId,
                        'status' => 'Posted',
                    ]);
                } catch (ValidationException $e) {
                    $errors[] = $label.': '.collect($e->errors())->flatten()->first();
                }
            }

            if ($errors !== []) {
                // Roll the whole sheet back — never half-posted.
                throw ValidationException::withMessages(['lines' => $errors]);
            }

            $totalReceive = $loanReceive->plus($depositReceive)->plus($shareReceive);
            $totalReceivable = $this->totalReceivableForMembers($memberIds);
            $batch->update([
                'loan_receive' => $this->money($loanReceive),
                'deposit_receive' => $this->money($depositReceive),
                'share_receive' => $this->money($shareReceive),
                'total_receive' => $this->money($totalReceive),
                'total_receivable' => $totalReceivable,
                'total_dues' => $this->money(BigDecimal::of($totalReceivable)->minus($totalReceive)),
            ]);

            CollectionPosted::dispatch($batch);

            return $batch->fresh('lines');
        });
    }

    /** Sum of receivables across every collectable account of the members. */
    public function totalReceivableForMembers(array $memberIds): string
    {
        $total = BigDecimal::zero();
        if ($memberIds === []) {
            return '0.00';
        }
        foreach (DepositAccount::whereIn('member_id', $memberIds)->where('is_active', true)->whereNull('closing_date')->get() as $a) {
            $total = $total->plus($this->depositReceivable($a));
        }
        foreach (LoanAccount::whereIn('member_id', $memberIds)->whereIn('status', ['Disbursed', 'Active'])->get() as $l) {
            $total = $total->plus($this->loanReceivable($l));
        }

        return $this->money($total);
    }

    /** Cancel one line through its engine's latest-only rule. */
    public function cancelLine(CollectionLine $line, User $by, string $reason): void
    {
        DB::transaction(function () use ($line, $by, $reason) {
            $line = CollectionLine::lockForUpdate()->findOrFail($line->id);
            if ($line->status === 'Cancelled') {
                throw ValidationException::withMessages(['cancel' => __('already_cancelled')]);
            }
            if ($line->account_type === 'deposit' && $line->ref_id) {
                $this->depositTxns->cancelTransaction(DepositTransaction::findOrFail($line->ref_id), $by, $reason);
            } elseif ($line->account_type === 'loan' && $line->ref_id) {
                $this->loanMoney->cancelLatest(LoanReceive::findOrFail($line->ref_id), $by, $reason);
            } elseif ($line->account_type === 'share' && $line->ref_id) {
                $this->posting->cancel(Voucher::findOrFail($line->ref_id), $by, $reason);
                $member = Member::findOrFail($line->member_id);
                $latest = AccountTransaction::where('account_type', 'share')
                    ->where('account_id', $member->id)->whereNull('cancel_at')->orderByDesc('id')->first();
                $member->update(['share_amount' => $latest ? (string) $latest->balance_after : '0.00']);
            }
            $line->update(['status' => 'Cancelled', 'cancel_by' => $by->id, 'cancel_at' => now()]);
            $this->refreshBatchAfterCancel($line->batch);
        });
    }

    /** Cancel all of one member's still-posted lines inside a batch. */
    public function cancelMemberLines(CollectionBatch $batch, int $memberId, User $by, string $reason): void
    {
        DB::transaction(function () use ($batch, $memberId, $by, $reason) {
            $lines = $batch->lines()->where('member_id', $memberId)
                ->where('status', 'Posted')->orderByDesc('id')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['cancel' => __('nothing_to_cancel')]);
            }
            foreach ($lines as $line) {
                $this->cancelLine($line, $by, $reason);
            }
        });
    }

    /** Cancel every still-posted line of a batch (each must be latest). */
    public function cancelBatch(CollectionBatch $batch, User $by, string $reason): void
    {
        DB::transaction(function () use ($batch, $by, $reason) {
            $batch = CollectionBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'Cancelled') {
                throw ValidationException::withMessages(['cancel' => __('already_cancelled')]);
            }
            foreach ($batch->lines()->where('status', 'Posted')->orderByDesc('id')->get() as $line) {
                $this->cancelLine($line, $by, $reason);
            }
            $batch->update([
                'status' => 'Cancelled', 'cancel_by' => $by->id,
                'cancel_at' => now(), 'cancel_reason' => $reason,
            ]);
        });
    }

    private function refreshBatchAfterCancel(CollectionBatch $batch): void
    {
        $posted = $batch->lines()->where('status', 'Posted')->get();
        $loan = BigDecimal::zero();
        $deposit = BigDecimal::zero();
        $share = BigDecimal::zero();
        foreach ($posted as $l) {
            match ($l->account_type) {
                'loan' => $loan = $loan->plus((string) $l->amount),
                'deposit' => $deposit = $deposit->plus((string) $l->amount),
                default => $share = $share->plus((string) $l->amount),
            };
        }
        $total = $loan->plus($deposit)->plus($share);
        $batch->update([
            'loan_receive' => $this->money($loan),
            'deposit_receive' => $this->money($deposit),
            'share_receive' => $this->money($share),
            'total_receive' => $this->money($total),
            'total_dues' => $this->money(BigDecimal::of((string) $batch->total_receivable)->minus($total)),
            'status' => $posted->isEmpty() ? 'Cancelled' : 'Posted',
        ]);
    }

    // --- Other Collection Receive (Step 21) -------------------------------

    public function postOther(User $actor, array $input): OtherCollection
    {
        return DB::transaction(function () use ($actor, $input) {
            $branchId = (int) $input['branch_id'];
            $businessDate = $this->businessDays->currentDate($branchId);
            $amount = $this->money(BigDecimal::of((string) $input['amount']));
            $voucher = $this->posting->post([
                'branch_id' => $branchId, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
                'comments' => $input['comments'] ?? null,
                'lines' => [[
                    'mapping_key' => 'collection.other', 'amount' => $amount,
                    'document_number' => $input['document_number'] ?? null,
                ]],
                'movements' => [
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => $amount],
                ],
            ]);

            return OtherCollection::create([
                'branch_id' => $branchId,
                'member_id' => $input['member_id'] ?? null,
                'collection_type' => $input['collection_type'],
                'collection_account' => $input['collection_account'] ?? null,
                'amount' => $amount,
                'tran_date' => $businessDate,
                'business_date' => $businessDate,
                'document_number' => $input['document_number'] ?? null,
                'comments' => $input['comments'] ?? null,
                'voucher_id' => $voucher->id,
                'entry_by' => $actor->id,
                'entry_at' => now(),
            ]);
        });
    }

    public function cancelOther(OtherCollection $other, User $by, string $reason): void
    {
        DB::transaction(function () use ($other, $by, $reason) {
            $other = OtherCollection::lockForUpdate()->findOrFail($other->id);
            if ($other->cancel_at !== null) {
                throw ValidationException::withMessages(['cancel' => __('already_cancelled')]);
            }
            if ($other->voucher_id) {
                $this->posting->cancel(Voucher::findOrFail($other->voucher_id), $by, $reason);
            }
            $other->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
        });
    }

    // --- Passbook & cheque book issues (Step 23) ---------------------------

    public function issuePassbook(User $actor, array $input): PassbookIssue
    {
        return DB::transaction(function () use ($actor, $input) {
            $member = Member::findOrFail((int) $input['member_id']);
            $businessDate = $this->businessDays->currentDate($member->branch_id);
            $voucherId = $this->postFee($actor, $member->branch_id, (string) ($input['fee_amount'] ?? '0'), $input['document_number'] ?? null, 'Passbook fee '.$input['book_number']);

            return PassbookIssue::create([
                'branch_id' => $member->branch_id,
                'member_id' => $member->id,
                'book_number' => $input['book_number'],
                'fee_amount' => $input['fee_amount'] ?? 0,
                'issue_date' => $businessDate,
                'business_date' => $businessDate,
                'document_number' => $input['document_number'] ?? null,
                'voucher_id' => $voucherId,
                'entry_by' => $actor->id,
                'entry_at' => now(),
            ]);
        });
    }

    public function issueChequeBook(User $actor, array $input): ChequeBookIssue
    {
        return DB::transaction(function () use ($actor, $input) {
            $member = Member::findOrFail((int) $input['member_id']);
            $businessDate = $this->businessDays->currentDate($member->branch_id);
            $voucherId = $this->postFee($actor, $member->branch_id, (string) ($input['fee_amount'] ?? '0'), $input['document_number'] ?? null, 'Cheque book fee '.($input['cheque_prefix'] ?? ''));

            return ChequeBookIssue::create([
                'branch_id' => $member->branch_id,
                'member_id' => $member->id,
                'cheque_prefix' => $input['cheque_prefix'] ?? null,
                'start_leaf' => $input['start_leaf'],
                'end_leaf' => $input['end_leaf'],
                'fee_amount' => $input['fee_amount'] ?? 0,
                'issue_date' => $businessDate,
                'business_date' => $businessDate,
                'document_number' => $input['document_number'] ?? null,
                'voucher_id' => $voucherId,
                'entry_by' => $actor->id,
                'entry_at' => now(),
            ]);
        });
    }

    public function cancelPassbook(PassbookIssue $issue, User $by, string $reason): void
    {
        $this->cancelInstrument($issue, $by, $reason);
    }

    public function cancelChequeBook(ChequeBookIssue $issue, User $by, string $reason): void
    {
        $this->cancelInstrument($issue, $by, $reason);
    }

    private function cancelInstrument(PassbookIssue|ChequeBookIssue $issue, User $by, string $reason): void
    {
        DB::transaction(function () use ($issue, $by, $reason) {
            $fresh = $issue->fresh();
            if ($fresh->cancel_at !== null) {
                throw ValidationException::withMessages(['cancel' => __('already_cancelled')]);
            }
            if ($fresh->voucher_id) {
                $this->posting->cancel(Voucher::findOrFail($fresh->voucher_id), $by, $reason);
            }
            $fresh->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
        });
    }

    /** Post an instrument fee (cash in / fee income) when it is positive. */
    private function postFee(User $actor, int $branchId, string $fee, ?string $documentNumber, string $comments): ?int
    {
        $amount = BigDecimal::of($fee);
        if (! $amount->isPositive()) {
            return null;
        }
        $voucher = $this->posting->post([
            'branch_id' => $branchId, 'voucher_type' => 'Receipt', 'entry_by' => $actor->id,
            'comments' => $comments,
            'lines' => [[
                'mapping_key' => 'collection.fee', 'amount' => $this->money($amount),
                'document_number' => $documentNumber,
            ]],
            'movements' => [
                ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_IN', 'amount' => $this->money($amount)],
            ],
        ]);

        return $voucher->id;
    }
}
