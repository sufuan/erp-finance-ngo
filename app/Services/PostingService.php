<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Branch;
use App\Models\CashLedger;
use App\Models\GlMapping;
use App\Models\Ledger;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Models\VoucherType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PostingService — the ONLY way money moves (plan Step 3A).
 *
 * Binding rules:
 *  - Ledgers resolve through gl_mappings; no hard-coded ledger codes.
 *  - Money math in brick/math BigDecimal only; a voucher must balance.
 *  - Row locks (SELECT … FOR UPDATE) only on sub-ledger account rows
 *    (cash_ledgers, latest account_transactions row) — NEVER on ledgers;
 *    GL balances are derived from voucher_lines sums.
 *  - Balances change at posting. Authorization is a status gate driven
 *    by voucher_types.require_authorization + the amount threshold.
 *  - Idempotency: an explicit batch_number that already exists makes
 *    post() a no-op returning the original voucher; the sub-ledger also
 *    has a unique (account_type, account_id, voucher_id, tran_code) key.
 */
class PostingService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly BusinessDayService $businessDays,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @param  array  $data  branch_id, voucher_type, entry_by, lines[], movements[],
     *                       optional: batch_number, transaction_date, comments, document_path
     */
    public function post(array $data): Voucher
    {
        // Idempotency by batch number: re-posting returns the original.
        if (! empty($data['batch_number'])) {
            if ($existing = Voucher::where('batch_number', $data['batch_number'])->first()) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data) {
            $branch = Branch::findOrFail($data['branch_id']);
            $businessDate = $this->businessDays->currentDate($branch->id);
            $transactionDate = $data['transaction_date'] ?? $businessDate;
            $this->businessDays->assertOpen($branch->id, $transactionDate);

            $type = VoucherType::where('code', $data['voucher_type'])->first()
                ?? throw new InvalidArgumentException("Unknown voucher type: {$data['voucher_type']}");

            $lines = $this->buildLines($data['lines'] ?? []);
            if ($lines === []) {
                throw new InvalidArgumentException('A voucher needs at least one line.');
            }

            $totalDebit = BigDecimal::zero();
            $totalCredit = BigDecimal::zero();
            foreach ($lines as $line) {
                $totalDebit = $totalDebit->plus($line['debit']);
                $totalCredit = $totalCredit->plus($line['credit']);
            }
            if (! $totalDebit->isEqualTo($totalCredit)) {
                throw new DomainException(__('voucher_must_balance'));
            }
            if ($totalDebit->isZero()) {
                throw new DomainException(__('voucher_amount_must_be_positive'));
            }
            $total = $totalDebit->toScale(2, RoundingMode::HalfUp);

            $threshold = (string) ($this->settings->get('accounts.auth_amount_threshold', 50000) ?? 50000);
            $requiresAuth = $type->require_authorization
                || $total->isGreaterThanOrEqualTo(BigDecimal::of($threshold));

            $voucher = Voucher::create([
                'branch_id' => $branch->id,
                'batch_number' => $data['batch_number'] ?? $this->numbering->displayId($branch, $this->numbering->next($branch->id, 'voucher')),
                'voucher_type' => $type->code,
                'transaction_date' => $transactionDate,
                'business_date' => $businessDate,
                'total_amount' => (string) $total,
                'comments' => $data['comments'] ?? null,
                'document_path' => $data['document_path'] ?? null,
                'entry_by' => $data['entry_by'],
                'entry_at' => now(),
                'status' => $requiresAuth ? 'Pending' : 'Authorized',
                'auth_by' => $requiresAuth ? null : $data['entry_by'],
                'auth_at' => $requiresAuth ? null : now(),
            ]);

            foreach ($lines as $line) {
                VoucherLine::create(['voucher_id' => $voucher->id] + $line);
            }

            foreach ($this->sortMovements($data['movements'] ?? []) as $movement) {
                $this->applyMovement($voucher, $movement, $transactionDate, $businessDate);
            }

            $this->audit->log($data['entry_by'], 'voucher.posted', 'vouchers', $voucher->id, null, [
                'batch_number' => $voucher->batch_number, 'total' => (string) $total, 'status' => $voucher->status,
            ]);

            return $voucher->fresh(['lines', 'accountTransactions']);
        });
    }

    /** Authorize a Pending voucher — maker ≠ checker (SoD), or break-glass. */
    public function authorize(Voucher $voucher, User $authorizer, bool $breakGlass = false, ?string $reason = null): Voucher
    {
        if ($voucher->status !== 'Pending') {
            throw new DomainException(__('voucher_not_pending'));
        }

        if ($breakGlass) {
            if (! $authorizer->can('accounts.breakglass') || blank($reason)) {
                throw new DomainException(__('breakglass_requires_permission_and_reason'));
            }
        } else {
            $this->authorization->assertDifferentMakerChecker($voucher->entry_by, $authorizer->id);
        }

        $voucher->update(['status' => 'Authorized', 'auth_by' => $authorizer->id, 'auth_at' => now()]);
        $this->audit->log($authorizer->id, $breakGlass ? 'voucher.breakglass_authorized' : 'voucher.authorized', 'vouchers', $voucher->id, null, [
            'batch_number' => $voucher->batch_number, 'reason' => $reason,
        ]);

        return $voucher;
    }

    /**
     * Cancel a voucher: flags the voucher + its sub-ledger rows and backs
     * the amounts out of the cached cash balances. (Full reversal-entry
     * flows for product modules build on this in Step 27.)
     */
    public function cancel(Voucher $voucher, User $by, string $reason): Voucher
    {
        if ($voucher->status === 'Cancelled') {
            throw new DomainException(__('voucher_already_cancelled'));
        }

        return DB::transaction(function () use ($voucher, $by, $reason) {
            foreach ($voucher->accountTransactions()->whereNull('cancel_at')->get() as $row) {
                if ($row->account_type === 'cash') {
                    $ledger = CashLedger::where('user_id', $row->account_id)->lockForUpdate()->first();
                    if ($ledger) {
                        $ledger->update(['current_balance' => (string) BigDecimal::of($ledger->current_balance)->minus($row->amount)->toScale(2)]);
                    }
                }
                $row->update(['cancel_by' => $by->id, 'cancel_at' => now()]);
            }

            $voucher->update(['status' => 'Cancelled', 'cancel_by' => $by->id, 'cancel_at' => now()]);
            $this->audit->log($by->id, 'voucher.cancelled', 'vouchers', $voucher->id, null, [
                'batch_number' => $voucher->batch_number, 'reason' => $reason,
            ]);

            return $voucher;
        });
    }

    /** Trial balance for a branch: total debits must equal total credits. */
    public function trialBalance(int $branchId): array
    {
        $row = VoucherLine::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_lines.voucher_id')
            ->where('vouchers.branch_id', $branchId)
            ->where('vouchers.status', '!=', 'Cancelled')
            ->selectRaw('COALESCE(SUM(voucher_lines.debit),0) AS debit, COALESCE(SUM(voucher_lines.credit),0) AS credit')
            ->first();

        return ['debit' => (string) $row->debit, 'credit' => (string) $row->credit, 'balanced' => BigDecimal::of((string) $row->debit)->isEqualTo(BigDecimal::of((string) $row->credit))];
    }

    /** GL balance of one ledger, derived from voucher_lines (never stored). */
    public function ledgerBalance(Ledger $ledger, ?int $branchId = null): string
    {
        $query = VoucherLine::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_lines.voucher_id')
            ->where('voucher_lines.ledger_id', $ledger->id)
            ->where('vouchers.status', '!=', 'Cancelled');
        if ($branchId) {
            $query->where('vouchers.branch_id', $branchId);
        }
        $row = $query->selectRaw('COALESCE(SUM(voucher_lines.debit),0) AS d, COALESCE(SUM(voucher_lines.credit),0) AS c')->first();

        // Debit-normal groups (Asset/Expense) report Dr−Cr; others Cr−Dr.
        $debitNormal = in_array($ledger->ledger_group, ['Asset', 'Expense'], true);
        $balance = $debitNormal
            ? BigDecimal::of((string) $row->d)->minus(BigDecimal::of((string) $row->c))
            : BigDecimal::of((string) $row->c)->minus(BigDecimal::of((string) $row->d));

        return (string) $balance->toScale(2);
    }

    // -- internals ---------------------------------------------------------

    private function buildLines(array $inputLines): array
    {
        $lines = [];
        foreach ($inputLines as $line) {
            $tags = [
                'deposit_account_id' => $line['deposit_account_id'] ?? null,
                'loan_account_id' => $line['loan_account_id'] ?? null,
                'comments' => $line['comments'] ?? null,
                'document_number' => $line['document_number'] ?? null,
            ];
            if (! empty($line['mapping_key'])) {
                $mapping = GlMapping::byKey($line['mapping_key'])
                    ?? throw new InvalidArgumentException("Unknown GL mapping: {$line['mapping_key']}");
                $amount = $this->money($line['amount'] ?? '0');
                if ($amount->isZero() || $amount->isNegative()) {
                    throw new DomainException(__('voucher_amount_must_be_positive'));
                }
                $lines[] = ['ledger_id' => $mapping->debit_ledger_id, 'debit' => (string) $amount, 'credit' => '0.00'] + $tags;
                $lines[] = ['ledger_id' => $mapping->credit_ledger_id, 'debit' => '0.00', 'credit' => (string) $amount] + $tags;
            } else {
                $lines[] = [
                    'ledger_id' => $line['ledger_id'],
                    'debit' => (string) $this->money($line['debit'] ?? '0'),
                    'credit' => (string) $this->money($line['credit'] ?? '0'),
                ] + $tags;
            }
        }

        return $lines;
    }

    /** Consistent lock ordering across concurrent postings. */
    private function sortMovements(array $movements): array
    {
        usort($movements, fn ($a, $b) => [$a['account_type'], $a['account_id']] <=> [$b['account_type'], $b['account_id']]);

        return $movements;
    }

    private function applyMovement(Voucher $voucher, array $movement, string $tranDate, string $businessDate): AccountTransaction
    {
        $amount = $this->money($movement['amount']);
        $type = $movement['account_type'];
        $accountId = (int) $movement['account_id'];

        if ($type === 'cash') {
            // The cash sub-ledger row is the lockable account row.
            CashLedger::query()->insertOrIgnore([
                'user_id' => $accountId, 'branch_id' => $voucher->branch_id,
                'current_balance' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $cash = CashLedger::where('user_id', $accountId)->lockForUpdate()->first();
            $previous = BigDecimal::of($cash->current_balance);
        } else {
            // Serialize on the account's latest sub-ledger row.
            $latest = AccountTransaction::where('account_type', $type)->where('account_id', $accountId)
                ->orderByDesc('id')->lockForUpdate()->first();
            $previous = $latest ? BigDecimal::of($latest->balance_after) : BigDecimal::zero();
        }

        $balanceAfter = $previous->plus($amount)->toScale(2, RoundingMode::HalfUp);

        $row = AccountTransaction::create([
            'branch_id' => $voucher->branch_id,
            'account_type' => $type,
            'account_id' => $accountId,
            'tran_code' => $movement['tran_code'],
            'amount' => (string) $amount,
            'balance_after' => (string) $balanceAfter,
            'tran_date' => $tranDate,
            'business_date' => $businessDate,
            'voucher_id' => $voucher->id,
            'ref_type' => $movement['ref_type'] ?? null,
            'ref_id' => $movement['ref_id'] ?? null,
            'entry_by' => $voucher->entry_by,
            'entry_at' => now(),
        ]);

        if ($type === 'cash') {
            $cash->update(['current_balance' => (string) $balanceAfter]);
        }

        return $row;
    }

    private function money(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }
}
